/* Local HTTP/3 harness server for session-ticket, early-data, and packet-loss contracts. */

use std::collections::HashMap;
use std::fs;
use std::net;
use std::net::ToSocketAddrs;
use std::path::Path;
use std::time::{Duration, Instant};

use mio::Events;
use mio::Interest;
use mio::Poll;
use mio::Token;

use quiche::h3::NameValue;

use ring::rand::SystemRandom;

const MAX_DATAGRAM_SIZE: usize = 1350;
const SOCKET_TOKEN: Token = Token(0);
const SESSION_TICKET_KEY: [u8; 48] = [0x2a; 48];

struct PendingResponse {
    body: Vec<u8>,
    written: usize,
}

struct PendingRequest {
    method: String,
    path: String,
    body: Vec<u8>,
    finished: bool,
    response_sent: bool,
}

struct LifecycleCapture {
    saw_initial: bool,
    saw_established: bool,
    saw_resumed: bool,
    saw_early_data_state: bool,
    saw_h3_open: bool,
    saw_request_stream_open: bool,
    saw_request_headers: bool,
    request_headers_in_early_data: bool,
    request_headers_after_established: bool,
    saw_request_body: bool,
    request_body_bytes: usize,
    saw_request_finished: bool,
    request_finished_before_response: bool,
    request_body_drained_before_response: bool,
    response_on_request_stream: bool,
    saw_response_headers: bool,
    response_headers_in_early_data: bool,
    response_headers_after_established: bool,
    saw_response_drain: bool,
    response_drained_before_close: bool,
    saw_draining: bool,
    saw_closed: bool,
    early_data_reason_code: u32,
    early_data_reason: &'static str,
    close_source: &'static str,
    peer_packets_received: usize,
    peer_packets_sent: usize,
}

fn main() {
    let mut args = std::env::args();
    let cmd = args
        .next()
        .unwrap_or_else(|| "king-http3-ticket-server".to_string());

    let cert = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let key = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let root = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let host = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let port = match args.next() {
        Some(value) => match value.parse::<u16>() {
            Ok(parsed) => parsed,
            Err(_) => usage_and_exit(&cmd),
        },
        None => usage_and_exit(&cmd),
    };

    let enable_early_data = match args.next() {
        Some(value) => match value.as_str() {
            "1" => true,
            "0" => false,
            _ => usage_and_exit(&cmd),
        },
        None => false,
    };

    let drop_established_datagram_budget = match args.next() {
        Some(value) => match value.parse::<usize>() {
            Ok(parsed) => parsed,
            Err(_) => usage_and_exit(&cmd),
        },
        None => 0,
    };

    let bind_addr = resolve_udp_bind(&host, port);
    let mut socket = mio::net::UdpSocket::bind(bind_addr).unwrap();
    let local_addr = socket.local_addr().unwrap();

    let mut poll = Poll::new().unwrap();
    let mut events = Events::with_capacity(128);

    poll.registry()
        .register(&mut socket, SOCKET_TOKEN, Interest::READABLE)
        .unwrap();

    let mut config = quiche::Config::new(quiche::PROTOCOL_VERSION).unwrap();
    config.load_cert_chain_from_pem_file(&cert).unwrap();
    config.load_priv_key_from_pem_file(&key).unwrap();
    config.set_application_protos(&[b"h3"]).unwrap();
    config.set_ticket_key(&SESSION_TICKET_KEY).unwrap();
    if enable_early_data {
        config.enable_early_data();
    }
    config.set_max_idle_timeout(5000);
    config.set_max_recv_udp_payload_size(MAX_DATAGRAM_SIZE);
    config.set_max_send_udp_payload_size(MAX_DATAGRAM_SIZE);
    config.set_initial_max_data(1_000_000);
    config.set_initial_max_stream_data_bidi_local(1_000_000);
    config.set_initial_max_stream_data_bidi_remote(1_000_000);
    config.set_initial_max_stream_data_uni(1_000_000);
    config.set_initial_max_streams_bidi(16);
    config.set_initial_max_streams_uni(16);
    config.set_disable_active_migration(true);

    println!("READY {}", local_addr.port());

    let rng = SystemRandom::new();
    let conn_id_seed =
        ring::hmac::Key::generate(ring::hmac::HMAC_SHA256, &rng).unwrap();

    let mut conn: Option<quiche::Connection> = None;
    let mut h3_conn: Option<quiche::h3::Connection> = None;
    let mut pending = HashMap::<u64, PendingResponse>::new();
    let mut requests = HashMap::<u64, PendingRequest>::new();
    let mut lifecycle = LifecycleCapture {
        saw_initial: false,
        saw_established: false,
        saw_resumed: false,
        saw_early_data_state: false,
        saw_h3_open: false,
        saw_request_stream_open: false,
        saw_request_headers: false,
        request_headers_in_early_data: false,
        request_headers_after_established: false,
        saw_request_body: false,
        request_body_bytes: 0,
        saw_request_finished: false,
        request_finished_before_response: false,
        request_body_drained_before_response: false,
        response_on_request_stream: false,
        saw_response_headers: false,
        response_headers_in_early_data: false,
        response_headers_after_established: false,
        saw_response_drain: false,
        response_drained_before_close: false,
        saw_draining: false,
        saw_closed: false,
        early_data_reason_code: 0,
        early_data_reason: "unknown",
        close_source: "none",
        peer_packets_received: 0,
        peer_packets_sent: 0,
    };
    let mut response_started = false;
    let mut response_idle_deadline: Option<Instant> = None;
    let mut drop_established_datagram_budget = drop_established_datagram_budget;
    let overall_deadline = Instant::now() + Duration::from_secs(10);
    let mut buf = [0; 65535];
    let mut out = [0; MAX_DATAGRAM_SIZE];

    while Instant::now() < overall_deadline {
        let timeout = match conn.as_ref().and_then(|active| active.timeout()) {
            Some(value) => value.min(Duration::from_millis(50)),
            None => Duration::from_millis(50),
        };

        poll.poll(&mut events, Some(timeout)).unwrap();

        if let Some(active) = conn.as_mut() {
            if events.is_empty() {
                active.on_timeout();
            }
        }

        'read: loop {
            let (len, from) = match socket.recv_from(&mut buf) {
                Ok(value) => value,
                Err(err) => {
                    if err.kind() == std::io::ErrorKind::WouldBlock {
                        break 'read;
                    }

                    panic!("recv_from() failed: {err:?}");
                },
            };

            let pkt_buf = &mut buf[..len];
            let hdr = match quiche::Header::from_slice(
                pkt_buf,
                quiche::MAX_CONN_ID_LEN,
            ) {
                Ok(value) => value,
                Err(_) => continue 'read,
            };

            if conn.is_none() {
                if hdr.ty != quiche::Type::Initial {
                    continue 'read;
                }

                lifecycle.saw_initial = true;

                if !quiche::version_is_supported(hdr.version) {
                    let written = quiche::negotiate_version(
                        &hdr.scid,
                        &hdr.dcid,
                        &mut out,
                    )
                    .unwrap();
                    socket.send_to(&out[..written], from).unwrap();
                    continue 'read;
                }

                let conn_id = ring::hmac::sign(&conn_id_seed, &hdr.dcid);
                let conn_id = &conn_id.as_ref()[..quiche::MAX_CONN_ID_LEN];
                let scid = quiche::ConnectionId::from_ref(conn_id);

                conn = Some(
                    quiche::accept(&scid, None, local_addr, from, &mut config)
                        .unwrap(),
                );
            }

            let active = conn.as_mut().unwrap();
            if drop_established_datagram_budget > 0 &&
                h3_conn.is_some() &&
                !response_started
            {
                drop_established_datagram_budget -= 1;
                continue 'read;
            }

            let recv_info = quiche::RecvInfo {
                to: local_addr,
                from,
            };

            if active.recv(pkt_buf, recv_info).is_err() {
                continue 'read;
            }

            if active.is_established() {
                lifecycle.saw_established = true;
            }

            if active.is_resumed() {
                lifecycle.saw_resumed = true;
            }

            if active.is_in_early_data() {
                lifecycle.saw_early_data_state = true;
            }

            lifecycle.early_data_reason_code = active.early_data_reason();
            lifecycle.early_data_reason =
                early_data_reason_name(lifecycle.early_data_reason_code);

            if h3_conn.is_none() &&
                (active.is_in_early_data() || active.is_established())
            {
                lifecycle.saw_h3_open = true;
                h3_conn = Some(
                    quiche::h3::Connection::with_transport(
                        active,
                        &quiche::h3::Config::new().unwrap(),
                    )
                    .unwrap(),
                );
            }

            if let Some(http3) = h3_conn.as_mut() {
                process_http3_events(
                    http3,
                    active,
                    &root,
                    &mut pending,
                    &mut requests,
                    &mut response_started,
                    &mut lifecycle,
                );
            }
        }

        if let (Some(http3), Some(active)) = (h3_conn.as_mut(), conn.as_mut()) {
            flush_pending_bodies(http3, active, &mut pending, &mut lifecycle);
        }

        if response_started && pending.is_empty() && response_idle_deadline.is_none() {
            response_idle_deadline =
                Some(Instant::now() + Duration::from_millis(2000));
        }

        if !pending.is_empty() {
            response_idle_deadline = None;
        }

        if let Some(active) = conn.as_mut() {
            flush_egress(&mut socket, active, &mut out);

            if active.is_draining() {
                if !lifecycle.saw_draining {
                    lifecycle.response_drained_before_close =
                        lifecycle.saw_response_drain;
                    if lifecycle.close_source == "none" {
                        lifecycle.close_source = "peer_draining_close";
                    }
                }
                lifecycle.saw_draining = true;
            }

            if active.is_closed() {
                if lifecycle.close_source == "none" {
                    lifecycle.response_drained_before_close =
                        lifecycle.saw_response_drain;
                    lifecycle.close_source = "peer_closed";
                }
                lifecycle.saw_closed = true;
                break;
            }
        }

        if let Some(deadline) = response_idle_deadline {
            if pending.is_empty() && Instant::now() >= deadline {
                if let Some(active) = conn.as_mut() {
                    lifecycle.response_drained_before_close =
                        response_started && lifecycle.saw_response_drain;
                    lifecycle.close_source = "server_idle_close";
                    active.close(true, 0, b"").ok();
                    flush_egress(&mut socket, active, &mut out);
                    if active.is_draining() {
                        lifecycle.saw_draining = true;
                    }
                    if active.is_closed() {
                        lifecycle.saw_closed = true;
                    }
                }
            }
        }
    }

    if let Some(active) = conn.as_ref() {
        let stats = active.stats();
        lifecycle.peer_packets_received = stats.recv;
        lifecycle.peer_packets_sent = stats.sent;
    }

    println!(
        "LIFECYCLE {{\"saw_initial\":{},\"saw_established\":{},\"saw_resumed\":{},\"saw_early_data_state\":{},\"saw_h3_open\":{},\"saw_request_stream_open\":{},\"saw_request_headers\":{},\"request_headers_in_early_data\":{},\"request_headers_after_established\":{},\"saw_request_body\":{},\"request_body_bytes\":{},\"saw_request_finished\":{},\"request_finished_before_response\":{},\"request_body_drained_before_response\":{},\"response_on_request_stream\":{},\"saw_response_headers\":{},\"response_headers_in_early_data\":{},\"response_headers_after_established\":{},\"saw_response_drain\":{},\"response_drained_before_close\":{},\"saw_draining\":{},\"saw_closed\":{},\"early_data_reason_code\":{},\"early_data_reason\":\"{}\",\"close_source\":\"{}\",\"peer_packets_received\":{},\"peer_packets_sent\":{}}}",
        lifecycle.saw_initial,
        lifecycle.saw_established,
        lifecycle.saw_resumed,
        lifecycle.saw_early_data_state,
        lifecycle.saw_h3_open,
        lifecycle.saw_request_stream_open,
        lifecycle.saw_request_headers,
        lifecycle.request_headers_in_early_data,
        lifecycle.request_headers_after_established,
        lifecycle.saw_request_body,
        lifecycle.request_body_bytes,
        lifecycle.saw_request_finished,
        lifecycle.request_finished_before_response,
        lifecycle.request_body_drained_before_response,
        lifecycle.response_on_request_stream,
        lifecycle.saw_response_headers,
        lifecycle.response_headers_in_early_data,
        lifecycle.response_headers_after_established,
        lifecycle.saw_response_drain,
        lifecycle.response_drained_before_close,
        lifecycle.saw_draining,
        lifecycle.saw_closed,
        lifecycle.early_data_reason_code,
        lifecycle.early_data_reason,
        lifecycle.close_source,
        lifecycle.peer_packets_received,
        lifecycle.peer_packets_sent,
    );
}

fn usage_and_exit(cmd: &str) -> ! {
    eprintln!(
        "Usage: {cmd} <cert> <key> <root> <host> <port> [enable_early_data] [drop_established_datagram_count]"
    );
    std::process::exit(1);
}

fn resolve_udp_bind(host: &str, port: u16) -> net::SocketAddr {
    (host, port)
        .to_socket_addrs()
        .unwrap()
        .find(|addr| matches!(addr, net::SocketAddr::V4(_) | net::SocketAddr::V6(_)))
        .unwrap()
}

fn process_http3_events(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    root: &str,
    pending: &mut HashMap<u64, PendingResponse>,
    requests: &mut HashMap<u64, PendingRequest>,
    response_started: &mut bool,
    lifecycle: &mut LifecycleCapture,
) {
    loop {
        match http3.poll(conn) {
            Ok((stream_id, quiche::h3::Event::Headers { list, .. })) => {
                let (method, path) = request_line_from_headers(&list);

                lifecycle.saw_request_stream_open = true;
                lifecycle.saw_request_headers = true;
                if conn.is_in_early_data() {
                    lifecycle.request_headers_in_early_data = true;
                }
                if conn.is_established() && !conn.is_in_early_data() {
                    lifecycle.request_headers_after_established = true;
                }

                requests.insert(
                    stream_id,
                    PendingRequest {
                        method,
                        path,
                        body: Vec::new(),
                        finished: false,
                        response_sent: false,
                    },
                );

                if let Some(request) = requests.get_mut(&stream_id) {
                    if request.method.eq_ignore_ascii_case("GET") {
                        if send_response_for_request(
                            http3,
                            conn,
                            root,
                            stream_id,
                            request,
                            pending,
                            response_started,
                            lifecycle,
                        )
                        .is_err()
                        {
                            conn.close(false, 0x1, b"response failure").ok();
                            return;
                        }

                        conn.stream_shutdown(stream_id, quiche::Shutdown::Read, 0)
                            .ok();
                    }
                }
            },

            Ok((stream_id, quiche::h3::Event::Data)) => {
                let mut scratch = [0; 4096];

                loop {
                    match http3.recv_body(conn, stream_id, &mut scratch) {
                        Ok(0) => break,
                        Ok(read) => {
                            lifecycle.saw_request_body = true;
                            lifecycle.request_body_bytes += read;

                            if let Some(request) = requests.get_mut(&stream_id) {
                                request.body.extend_from_slice(&scratch[..read]);
                            }
                        },
                        Err(quiche::h3::Error::Done) => break,
                        Err(_) => break,
                    }
                }
            },

            Ok((stream_id, quiche::h3::Event::Finished)) => {
                lifecycle.saw_request_finished = true;

                if let Some(request) = requests.get_mut(&stream_id) {
                    request.finished = true;

                    if !request.response_sent {
                        lifecycle.request_finished_before_response = true;
                        lifecycle.request_body_drained_before_response = true;

                        if send_response_for_request(
                            http3,
                            conn,
                            root,
                            stream_id,
                            request,
                            pending,
                            response_started,
                            lifecycle,
                        )
                        .is_err()
                        {
                            conn.close(false, 0x1, b"response failure").ok();
                            return;
                        }
                    }

                    conn.stream_shutdown(stream_id, quiche::Shutdown::Read, 0).ok();
                }
            },
            Ok((_stream_id, quiche::h3::Event::Reset { .. })) => (),
            Ok((_stream_id, quiche::h3::Event::PriorityUpdate)) => (),
            Ok((_stream_id, quiche::h3::Event::GoAway)) => (),

            Err(quiche::h3::Error::Done) => break,

            Err(_) => {
                conn.close(false, 0x1, b"http3 failure").ok();
                break;
            },
        }
    }
}

fn send_response_for_request(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    root: &str,
    stream_id: u64,
    request: &mut PendingRequest,
    pending: &mut HashMap<u64, PendingResponse>,
    response_started: &mut bool,
    lifecycle: &mut LifecycleCapture,
) -> Result<(), ()> {
    let (status_code, body) =
        build_response(root, &request.method, &request.path, &request.body);
    let status = status_code.to_string();
    let content_length = body.len().to_string();
    let early_data_phase = if conn.is_in_early_data() {
        b"early_data".as_slice()
    } else {
        b"established".as_slice()
    };
    let headers = [
        quiche::h3::Header::new(b":status", status.as_bytes()),
        quiche::h3::Header::new(b"server", b"king-http3-ticket-server"),
        quiche::h3::Header::new(b"content-length", content_length.as_bytes()),
        quiche::h3::Header::new(b"x-king-early-data-phase", early_data_phase),
    ];

    *response_started = true;

    http3.send_response(conn, stream_id, &headers, body.is_empty())
        .map_err(|_| ())?;

    request.response_sent = true;
    lifecycle.saw_response_headers = true;
    lifecycle.response_on_request_stream = true;
    if conn.is_in_early_data() {
        lifecycle.response_headers_in_early_data = true;
    }
    if conn.is_established() && !conn.is_in_early_data() {
        lifecycle.response_headers_after_established = true;
    }

    if !body.is_empty() {
        pending.insert(stream_id, PendingResponse { body, written: 0 });
    } else {
        lifecycle.saw_response_drain = true;
    }

    Ok(())
}

fn flush_pending_bodies(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    pending: &mut HashMap<u64, PendingResponse>,
    lifecycle: &mut LifecycleCapture,
) {
    let mut completed = Vec::new();

    for (stream_id, response) in pending.iter_mut() {
        let body = &response.body[response.written..];
        if body.is_empty() {
            completed.push(*stream_id);
            continue;
        }

        match http3.send_body(conn, *stream_id, body, true) {
            Ok(written) => {
                response.written += written;
                if response.written == response.body.len() {
                    lifecycle.saw_response_drain = true;
                    completed.push(*stream_id);
                }
            },
            Err(quiche::h3::Error::Done) => (),
            Err(quiche::h3::Error::StreamBlocked) => (),
            Err(_) => {
                conn.close(false, 0x1, b"body failure").ok();
                completed.push(*stream_id);
            },
        }
    }

    for stream_id in completed {
        pending.remove(&stream_id);
    }
}

fn flush_egress(
    socket: &mut mio::net::UdpSocket,
    conn: &mut quiche::Connection,
    out: &mut [u8; MAX_DATAGRAM_SIZE],
) {
    loop {
        let (written, send_info) = match conn.send(out) {
            Ok(value) => value,
            Err(quiche::Error::Done) => break,
            Err(_) => {
                conn.close(false, 0x1, b"send failure").ok();
                break;
            },
        };

        if socket.send_to(&out[..written], send_info.to).is_err() {
            conn.close(false, 0x1, b"socket send failure").ok();
            break;
        }
    }
}

fn request_line_from_headers(headers: &[quiche::h3::Header]) -> (String, String) {
    let mut method = None;
    let mut path = None;

    for header in headers {
        match header.name() {
            b":method" => method = Some(String::from_utf8_lossy(header.value()).to_string()),
            b":path" => path = Some(String::from_utf8_lossy(header.value()).to_string()),
            _ => (),
        }
    }

    (
        method.unwrap_or_else(|| "GET".to_string()),
        path.unwrap_or_else(|| "/".to_string()),
    )
}

fn build_response(
    root: &str,
    method: &str,
    path: &str,
    request_body: &[u8],
) -> (u16, Vec<u8>) {
    if !method.eq_ignore_ascii_case("GET") &&
        !(method.eq_ignore_ascii_case("POST") &&
            (path == "/stream-lifecycle" || path == "/congestion-control"))
    {
        return (405, b"method not allowed\n".to_vec());
    }

    if path.contains("..") || path.contains('\\') {
        return (400, b"invalid path\n".to_vec());
    }

    if method.eq_ignore_ascii_case("POST") && path == "/stream-lifecycle" {
        let mut body = b"stream-ack:".to_vec();
        body.extend_from_slice(request_body);
        return (200, body);
    }

    if method.eq_ignore_ascii_case("POST") && path == "/congestion-control" {
        return (
            200,
            format!("congestion-ack:{}", request_body.len()).into_bytes(),
        );
    }

    let relative = path.trim_start_matches('/');
    let file_path = Path::new(root).join(relative);

    match fs::read(file_path) {
        Ok(body) => (200, body),
        Err(_) => (404, b"not found\n".to_vec()),
    }
}

fn early_data_reason_name(reason: u32) -> &'static str {
    match reason {
        0 => "unknown",
        1 => "disabled",
        2 => "accepted",
        3 => "protocol_version",
        4 => "peer_declined",
        5 => "no_session_offered",
        6 => "session_not_resumed",
        7 => "unsupported_for_session",
        8 => "hello_retry_request",
        9 => "alpn_mismatch",
        10 => "channel_id",
        12 => "ticket_age_skew",
        13 => "quic_parameter_mismatch",
        14 => "alps_mismatch",
        _ => "unknown_reason_code",
    }
}

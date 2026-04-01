#[macro_use]
extern crate log;

use std::collections::BTreeMap;
use std::net;
use std::net::ToSocketAddrs;
use std::time;

use mio::Events;
use mio::Interest;
use mio::Poll;
use mio::Token;

use quiche::h3::NameValue;
use quiche_apps::common::alpns;

use ring::rand::SystemRandom;

const MAX_DATAGRAM_SIZE: usize = 1350;
const SOCKET_TOKEN: Token = Token(0);

struct SessionState {
    active_streams: usize,
    max_active_streams: usize,
    connection_id: usize,
    handled: usize,
}

struct PendingResponse {
    path: String,
    active_at_start: usize,
    ready_at: time::Instant,
    headers_sent: bool,
    finish_order: usize,
    body: Vec<u8>,
    written: usize,
}

fn main() {
    env_logger::builder().format_timestamp_nanos().init();

    let mut args = std::env::args();
    let cmd = args.next().unwrap_or_else(|| "king-http3-multi-peer".to_string());

    let cert = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let key = match args.next() {
        Some(value) => value,
        None => usage_and_exit(&cmd),
    };

    let host = args.next().unwrap_or_else(|| "127.0.0.1".to_string());

    let expected_requests = match args.next() {
        Some(value) => match value.parse::<usize>() {
            Ok(parsed) if parsed > 0 => parsed,
            _ => usage_and_exit(&cmd),
        },
        None => 3,
    };

    let bind_addr = resolve_udp_bind(&host);
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
    config.set_application_protos(&alpns::HTTP_3).unwrap();
    config.set_max_idle_timeout(10_000);
    config.set_max_recv_udp_payload_size(MAX_DATAGRAM_SIZE);
    config.set_max_send_udp_payload_size(MAX_DATAGRAM_SIZE);
    config.set_initial_max_data(1_000_000);
    config.set_initial_max_stream_data_bidi_local(1_000_000);
    config.set_initial_max_stream_data_bidi_remote(1_000_000);
    config.set_initial_max_stream_data_uni(1_000_000);
    config.set_initial_max_streams_bidi(32);
    config.set_initial_max_streams_uni(16);
    config.set_disable_active_migration(true);

    println!("READY {}", local_addr.port());

    let rng = SystemRandom::new();
    let conn_id_seed =
        ring::hmac::Key::generate(ring::hmac::HMAC_SHA256, &rng).unwrap();

    let mut conn: Option<quiche::Connection> = None;
    let mut h3_conn: Option<quiche::h3::Connection> = None;
    let mut state = SessionState {
        active_streams: 0,
        max_active_streams: 0,
        connection_id: 1,
        handled: 0,
    };
    let mut pending = BTreeMap::<u64, PendingResponse>::new();
    let mut close_deadline: Option<time::Instant> = None;
    let overall_deadline = time::Instant::now() + time::Duration::from_secs(15);
    let mut buf = [0; 65535];
    let mut out = [0; MAX_DATAGRAM_SIZE];

    while time::Instant::now() < overall_deadline {
        let timeout = match conn.as_ref().and_then(|active| active.timeout()) {
            Some(value) => value.min(time::Duration::from_millis(25)),
            None => time::Duration::from_millis(25),
        };

        poll.poll(&mut events, Some(timeout)).unwrap();

        if events.is_empty() {
            if let Some(active) = conn.as_mut() {
                active.on_timeout();
                flush_egress(&mut socket, active, &mut out);
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
                Err(err) => {
                    warn!("failed to parse QUIC header: {err:?}");
                    continue 'read;
                },
            };

            if conn.is_none() {
                if hdr.ty != quiche::Type::Initial {
                    continue 'read;
                }

                if !quiche::version_is_supported(hdr.version) {
                    let written = quiche::negotiate_version(&hdr.scid, &hdr.dcid, &mut out).unwrap();
                    socket.send_to(&out[..written], from).unwrap();
                    continue 'read;
                }

                let conn_id = ring::hmac::sign(&conn_id_seed, &hdr.dcid);
                let conn_id = &conn_id.as_ref()[..quiche::MAX_CONN_ID_LEN];
                let scid = quiche::ConnectionId::from_ref(conn_id);

                info!("accepting HTTP/3 multi peer connection");
                conn = Some(
                    quiche::accept(&scid, None, local_addr, from, &mut config)
                        .unwrap(),
                );
            }

            let active = conn.as_mut().unwrap();
            let recv_info = quiche::RecvInfo {
                to: local_addr,
                from,
            };

            if active.recv(pkt_buf, recv_info).is_err() {
                continue 'read;
            }

            if h3_conn.is_none() && active.is_established() {
                h3_conn = Some(
                    quiche::h3::Connection::with_transport(
                        active,
                        &quiche::h3::Config::new().unwrap(),
                    )
                    .unwrap(),
                );
            }

            if let Some(http3) = h3_conn.as_mut() {
                process_http3_events(http3, active, &mut pending, &mut state);
            }
        }

        if let (Some(http3), Some(active)) = (h3_conn.as_mut(), conn.as_mut()) {
            flush_due_responses(
                http3,
                active,
                &mut pending,
                &mut state,
                expected_requests,
            );
        }

        if pending.is_empty()
            && state.handled >= expected_requests
            && close_deadline.is_none()
        {
            close_deadline = Some(time::Instant::now() + time::Duration::from_millis(150));
        }

        if !pending.is_empty() {
            close_deadline = None;
        }

        if let Some(active) = conn.as_mut() {
            flush_egress(&mut socket, active, &mut out);

            if active.is_closed() {
                return;
            }
        }

        if let Some(deadline) = close_deadline {
            if time::Instant::now() >= deadline {
                if let Some(active) = conn.as_mut() {
                    active.close(true, 0, b"").ok();
                    flush_egress(&mut socket, active, &mut out);
                }
                return;
            }
        }
    }
}

fn usage_and_exit(cmd: &str) -> ! {
    eprintln!("Usage: {cmd} <cert> <key> [host] [expected_requests]");
    std::process::exit(1);
}

fn resolve_udp_bind(host: &str) -> net::SocketAddr {
    (host, 0)
        .to_socket_addrs()
        .unwrap()
        .find(|addr| matches!(addr, net::SocketAddr::V4(_) | net::SocketAddr::V6(_)))
        .unwrap()
}

fn response_delay_ms(path: &str) -> u64 {
    match path {
        "/slow" => 350,
        "/fast-b" => 50,
        "/after" => 10,
        _ => 25,
    }
}

fn process_http3_events(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    pending: &mut BTreeMap<u64, PendingResponse>,
    state: &mut SessionState,
) {
    loop {
        match http3.poll(conn) {
            Ok((stream_id, quiche::h3::Event::Headers { list, .. })) => {
                conn.stream_shutdown(stream_id, quiche::Shutdown::Read, 0).ok();

                let path = extract_path(&list).unwrap_or_else(|| "/".to_string());
                let delay_ms = response_delay_ms(&path);
                state.active_streams += 1;
                state.max_active_streams =
                    state.max_active_streams.max(state.active_streams);

                pending.insert(
                    stream_id,
                    PendingResponse {
                        path,
                        active_at_start: state.active_streams,
                        ready_at: time::Instant::now()
                            + time::Duration::from_millis(delay_ms),
                        headers_sent: false,
                        finish_order: 0,
                        body: Vec::new(),
                        written: 0,
                    },
                );
            },

            Ok((stream_id, quiche::h3::Event::Data)) => {
                let mut scratch = [0_u8; 4096];

                loop {
                    match http3.recv_body(conn, stream_id, &mut scratch) {
                        Ok(0) => break,
                        Ok(_) => (),
                        Err(quiche::h3::Error::Done) => break,
                        Err(_) => break,
                    }
                }
            },

            Ok((_stream_id, quiche::h3::Event::Finished)) => (),
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

fn flush_due_responses(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    pending: &mut BTreeMap<u64, PendingResponse>,
    state: &mut SessionState,
    expected_requests: usize,
) {
    let now = time::Instant::now();
    let mut completed = Vec::new();

    for (stream_id, response) in pending.iter_mut() {
        if !response.headers_sent {
            let status;
            let connection_id;
            let finish_order;
            let active_at_start;
            let max_active_streams;
            let headers;

            if now < response.ready_at {
                continue;
            }

            if state.handled >= expected_requests {
                continue;
            }

            state.handled += 1;
            response.finish_order = state.handled;
            response.body = format!(
                "{{\"connectionId\":{},\"path\":\"{}\",\"activeAtStart\":{},\"maxActiveStreams\":{},\"finishOrder\":{}}}",
                state.connection_id,
                response.path,
                response.active_at_start,
                state.max_active_streams,
                response.finish_order
            )
            .into_bytes();

            status = 200_u16.to_string();
            connection_id = state.connection_id.to_string();
            finish_order = response.finish_order.to_string();
            active_at_start = response.active_at_start.to_string();
            max_active_streams = state.max_active_streams.to_string();
            let content_length = response.body.len().to_string();

            headers = [
                quiche::h3::Header::new(b":status", status.as_bytes()),
                quiche::h3::Header::new(b"content-type", b"application/json"),
                quiche::h3::Header::new(b"content-length", content_length.as_bytes()),
                quiche::h3::Header::new(b"x-connection-id", connection_id.as_bytes()),
                quiche::h3::Header::new(b"x-finish-order", finish_order.as_bytes()),
                quiche::h3::Header::new(b"x-active-at-start", active_at_start.as_bytes()),
                quiche::h3::Header::new(b"x-max-active-streams", max_active_streams.as_bytes()),
            ];

            match http3.send_response(conn, *stream_id, &headers, response.body.is_empty()) {
                Ok(_) => {
                    response.headers_sent = true;
                    if response.body.is_empty() {
                        completed.push(*stream_id);
                    }
                },
                Err(quiche::h3::Error::Done) => {
                    state.handled -= 1;
                    response.finish_order = 0;
                    response.body.clear();
                    continue;
                },
                Err(quiche::h3::Error::StreamBlocked) => {
                    state.handled -= 1;
                    response.finish_order = 0;
                    response.body.clear();
                    continue;
                },
                Err(_) => {
                    conn.close(false, 0x1, b"response failure").ok();
                    return;
                },
            }
        }

        if response.headers_sent && response.written < response.body.len() {
            let body = &response.body[response.written..];
            match http3.send_body(conn, *stream_id, body, true) {
                Ok(written) => {
                    response.written += written;
                    if response.written == response.body.len() {
                        completed.push(*stream_id);
                    }
                },
                Err(quiche::h3::Error::Done) => (),
                Err(quiche::h3::Error::StreamBlocked) => (),
                Err(_) => {
                    conn.close(false, 0x1, b"body failure").ok();
                    return;
                },
            }
        }
    }

    for stream_id in completed {
        if pending.remove(&stream_id).is_some() && state.active_streams > 0 {
            state.active_streams -= 1;
        }
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

fn extract_path(headers: &[quiche::h3::Header]) -> Option<String> {
    for header in headers {
        if header.name() == b":path" {
            return Some(String::from_utf8_lossy(header.value()).to_string());
        }
    }

    None
}

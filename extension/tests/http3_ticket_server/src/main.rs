/* Local HTTP/3 harness server for session-ticket reuse and shared ticket-ring contracts. */

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
    let mut response_started = false;
    let mut response_idle_deadline: Option<Instant> = None;
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
            let recv_info = quiche::RecvInfo {
                to: local_addr,
                from,
            };

            if active.recv(pkt_buf, recv_info).is_err() {
                continue 'read;
            }

            if h3_conn.is_none() &&
                (active.is_in_early_data() || active.is_established())
            {
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
                    &mut response_started,
                );
            }
        }

        if let (Some(http3), Some(active)) = (h3_conn.as_mut(), conn.as_mut()) {
            flush_pending_bodies(http3, active, &mut pending);
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

            if active.is_closed() {
                break;
            }
        }

        if let Some(deadline) = response_idle_deadline {
            if pending.is_empty() && Instant::now() >= deadline {
                if let Some(active) = conn.as_mut() {
                    active.close(true, 0, b"").ok();
                    flush_egress(&mut socket, active, &mut out);
                }
                break;
            }
        }
    }
}

fn usage_and_exit(cmd: &str) -> ! {
    eprintln!("Usage: {cmd} <cert> <key> <root> <host> <port> [enable_early_data]");
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
    response_started: &mut bool,
) {
    loop {
        match http3.poll(conn) {
            Ok((stream_id, quiche::h3::Event::Headers { list, .. })) => {
                conn.stream_shutdown(stream_id, quiche::Shutdown::Read, 0).ok();
                *response_started = true;

                let (status_code, body) = build_response(root, &list);
                let status = status_code.to_string();
                let content_length = body.len().to_string();
                let early_data_phase = if conn.is_in_early_data() {
                    b"early_data".as_slice()
                } else {
                    b"established".as_slice()
                };
                let headers = [
                    quiche::h3::Header::new(b":status", status.as_bytes()),
                    quiche::h3::Header::new(
                        b"server",
                        b"king-http3-ticket-server",
                    ),
                    quiche::h3::Header::new(
                        b"content-length",
                        content_length.as_bytes(),
                    ),
                    quiche::h3::Header::new(
                        b"x-king-early-data-phase",
                        early_data_phase,
                    ),
                ];

                if http3
                    .send_response(conn, stream_id, &headers, body.is_empty())
                    .is_err()
                {
                    conn.close(false, 0x1, b"response failure").ok();
                    return;
                }

                if !body.is_empty() {
                    pending.insert(
                        stream_id,
                        PendingResponse { body, written: 0 },
                    );
                }
            },

            Ok((stream_id, quiche::h3::Event::Data)) => {
                let mut scratch = [0; 4096];

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

fn flush_pending_bodies(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    pending: &mut HashMap<u64, PendingResponse>,
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

fn build_response(
    root: &str,
    headers: &[quiche::h3::Header],
) -> (u16, Vec<u8>) {
    let mut method = None;
    let mut path = None;

    for header in headers {
        match header.name() {
            b":method" => method = Some(String::from_utf8_lossy(header.value()).to_string()),
            b":path" => path = Some(String::from_utf8_lossy(header.value()).to_string()),
            _ => (),
        }
    }

    if method.as_deref() != Some("GET") {
        return (405, b"method not allowed\n".to_vec());
    }

    let Some(path) = path else {
        return (400, b"missing path\n".to_vec());
    };

    if path.contains("..") || path.contains('\\') {
        return (400, b"invalid path\n".to_vec());
    }

    let relative = path.trim_start_matches('/');
    let file_path = Path::new(root).join(relative);

    match fs::read(file_path) {
        Ok(body) => (200, body),
        Err(_) => (404, b"not found\n".to_vec()),
    }
}

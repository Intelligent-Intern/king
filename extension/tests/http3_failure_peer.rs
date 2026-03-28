#[macro_use]
extern crate log;

use std::net;
use std::net::ToSocketAddrs;
use std::time;

use mio::Events;
use mio::Interest;
use mio::Poll;
use mio::Token;

use quiche_apps::common::alpns;

use ring::rand::SystemRandom;

const MAX_DATAGRAM_SIZE: usize = 1350;
const SOCKET_TOKEN: Token = Token(0);

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum Mode {
    HandshakeReject,
    TransportClose,
}

fn main() {
    env_logger::builder().format_timestamp_nanos().init();

    let mut args = std::env::args();
    let cmd = args.next().unwrap_or_else(|| "king-http3-failure-peer".to_string());
    let mode = match args.next().as_deref() {
        Some("handshake_reject") => Mode::HandshakeReject,
        Some("transport_close") => Mode::TransportClose,
        _ => {
            eprintln!("Usage: {cmd} <handshake_reject|transport_close> <cert> <key> [host]");
            std::process::exit(1);
        },
    };

    let cert = match args.next() {
        Some(value) => value,
        None => {
            eprintln!("missing certificate path");
            std::process::exit(1);
        },
    };

    let key = match args.next() {
        Some(value) => value,
        None => {
            eprintln!("missing key path");
            std::process::exit(1);
        },
    };

    let host = args.next().unwrap_or_else(|| "127.0.0.1".to_string());

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

    match mode {
        Mode::HandshakeReject => {
            config.set_application_protos(&alpns::HTTP_09).unwrap();
        },
        Mode::TransportClose => {
            config.set_application_protos(&alpns::HTTP_3).unwrap();
        },
    }

    config.set_max_idle_timeout(3000);
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
    let mut close_sent = false;
    let deadline = time::Instant::now() + time::Duration::from_secs(10);
    let mut buf = [0; 65535];
    let mut out = [0; MAX_DATAGRAM_SIZE];

    while time::Instant::now() < deadline {
        let timeout = match conn.as_ref().and_then(|active| active.timeout()) {
            Some(value) => value.min(time::Duration::from_millis(100)),
            None => time::Duration::from_millis(100),
        };

        poll.poll(&mut events, Some(timeout)).unwrap();

        if events.is_empty() {
            if let Some(active) = conn.as_mut() {
                active.on_timeout();
                flush_egress(&mut socket, active, &mut out);
            }
            continue;
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

            trace!("got {len} bytes from {from}");

            let pkt_buf = &mut buf[..len];
            let hdr = match quiche::Header::from_slice(
                pkt_buf,
                quiche::MAX_CONN_ID_LEN,
            ) {
                Ok(value) => value,
                Err(err) => {
                    error!("failed to parse QUIC header: {err:?}");
                    continue 'read;
                },
            };

            if conn.is_none() {
                if hdr.ty != quiche::Type::Initial {
                    warn!("ignoring non-Initial packet before connection exists");
                    continue 'read;
                }

                if !quiche::version_is_supported(hdr.version) {
                    let written = quiche::negotiate_version(&hdr.scid, &hdr.dcid, &mut out).unwrap();
                    let out = &out[..written];
                    socket.send_to(out, from).unwrap();
                    continue 'read;
                }

                let conn_id = ring::hmac::sign(&conn_id_seed, &hdr.dcid);
                let conn_id = &conn_id.as_ref()[..quiche::MAX_CONN_ID_LEN];
                let scid = quiche::ConnectionId::from_ref(conn_id);

                info!("accepting failure-peer connection with mode={mode:?}");
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

            match active.recv(pkt_buf, recv_info) {
                Ok(processed) => {
                    trace!(
                        "{} processed {} bytes established={} closed={}",
                        active.trace_id(),
                        processed,
                        active.is_established(),
                        active.is_closed()
                    );
                },
                Err(err) => {
                    warn!("{} recv failed: {err:?}", active.trace_id());
                    continue 'read;
                },
            }

            if mode == Mode::TransportClose && active.is_established() && !close_sent {
                info!("sending deterministic QUIC transport close");
                active
                    .close(false, 0x1337, b"test transport abort")
                    .ok();
                close_sent = true;
            }

            flush_egress(&mut socket, active, &mut out);

            if active.is_closed() {
                log_close_state(active);
                return;
            }
        }
    }

    if let Some(active) = conn.as_ref() {
        log_close_state(active);
    }
}

fn resolve_udp_bind(host: &str) -> net::SocketAddr {
    (host, 0)
        .to_socket_addrs()
        .unwrap()
        .find(|addr| matches!(addr, net::SocketAddr::V4(_) | net::SocketAddr::V6(_)))
        .unwrap()
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
            Err(err) => {
                error!("{} send failed: {err:?}", conn.trace_id());
                conn.close(false, 0x1, b"send failure").ok();
                break;
            },
        };

        socket.send_to(&out[..written], send_info.to).unwrap();
    }
}

fn log_close_state(conn: &quiche::Connection) {
    if let Some(peer_error) = conn.peer_error() {
        info!("{} peer_error={peer_error:?}", conn.trace_id());
    }

    if let Some(local_error) = conn.local_error() {
        info!("{} local_error={local_error:?}", conn.trace_id());
    }

    info!("{} closed stats={:?}", conn.trace_id(), conn.stats());
}

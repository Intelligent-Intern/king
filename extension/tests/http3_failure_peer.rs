/* HTTP/3 peer harness for transport-close, application-close, idle-timeout, and slow-peer contracts. */

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
const APPLICATION_CLOSE_CODE: u64 = 0x1234;
const APPLICATION_CLOSE_REASON: &[u8] = b"test application abort";
const IDLE_TIMEOUT_MS: u64 = 250;
const RESET_STREAM_CODE: u64 = 66;
const STOP_SENDING_CODE: u64 = 67;

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum Mode {
    HandshakeReject,
    TransportClose,
    ApplicationClose,
    IdleTimeout,
    SlowResponse,
    SlowReader,
    ResetStream,
    StopSending,
}

struct CloseCapture {
    saw_initial: bool,
    saw_established: bool,
    saw_h3_open: bool,
    saw_request_headers: bool,
    saw_request_body: bool,
    saw_request_finished: bool,
    request_body_bytes: usize,
    close_trigger: &'static str,
    stream_trigger: &'static str,
    reset_stream_sent: bool,
    reset_stream_code: u64,
    stop_sending_sent: bool,
    stop_sending_code: u64,
}

fn main() {
    env_logger::builder().format_timestamp_nanos().init();

    let mut args = std::env::args();
    let cmd = args.next().unwrap_or_else(|| "king-http3-failure-peer".to_string());
    let mode = match args.next().as_deref() {
        Some("handshake_reject") => Mode::HandshakeReject,
        Some("transport_close") => Mode::TransportClose,
        Some("application_close") => Mode::ApplicationClose,
        Some("idle_timeout") => Mode::IdleTimeout,
        Some("slow_response") => Mode::SlowResponse,
        Some("slow_reader") => Mode::SlowReader,
        Some("reset_stream") => Mode::ResetStream,
        Some("stop_sending") => Mode::StopSending,
        _ => {
            eprintln!(
                "Usage: {cmd} <handshake_reject|transport_close|application_close|idle_timeout|slow_response|slow_reader|reset_stream|stop_sending> <cert> <key> [host]"
            );
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
        Mode::HandshakeReject => config.set_application_protos(&alpns::HTTP_09).unwrap(),
        Mode::TransportClose |
        Mode::ApplicationClose |
        Mode::IdleTimeout |
        Mode::SlowResponse |
        Mode::SlowReader |
        Mode::ResetStream |
        Mode::StopSending => {
            config.set_application_protos(&alpns::HTTP_3).unwrap();
        },
    }

    config.set_max_idle_timeout(match mode {
        Mode::IdleTimeout => IDLE_TIMEOUT_MS,
        _ => 10_000,
    });
    config.set_max_recv_udp_payload_size(MAX_DATAGRAM_SIZE);
    config.set_max_send_udp_payload_size(MAX_DATAGRAM_SIZE);
    if matches!(mode, Mode::SlowReader | Mode::StopSending) {
        config.set_initial_max_data(4096);
    } else {
        config.set_initial_max_data(1_000_000);
    }
    config.set_initial_max_stream_data_bidi_local(1_000_000);
    if matches!(mode, Mode::SlowReader | Mode::StopSending) {
        config.set_initial_max_stream_data_bidi_remote(4096);
    } else {
        config.set_initial_max_stream_data_bidi_remote(1_000_000);
    }
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
    let mut close_sent = false;
    let mut stall_after_request = false;
    let mut stream_action_sent = false;
    let mut capture = CloseCapture {
        saw_initial: false,
        saw_established: false,
        saw_h3_open: false,
        saw_request_headers: false,
        saw_request_body: false,
        saw_request_finished: false,
        request_body_bytes: 0,
        close_trigger: "none",
        stream_trigger: "none",
        reset_stream_sent: false,
        reset_stream_code: 0,
        stop_sending_sent: false,
        stop_sending_code: 0,
    };
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
                if active.is_timed_out() {
                    capture.close_trigger = "idle_timeout";
                }
                if !stall_after_request {
                    flush_egress(&mut socket, active, &mut out);
                }
                if active.is_closed() {
                    emit_close_capture(mode, &capture, active);
                    return;
                }
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

                capture.saw_initial = true;

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

            if stall_after_request {
                continue 'read;
            }

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

            if active.is_established() {
                capture.saw_established = true;
            }

            if matches!(
                mode,
                Mode::ApplicationClose
                    | Mode::IdleTimeout
                    | Mode::SlowResponse
                    | Mode::SlowReader
                    | Mode::ResetStream
                    | Mode::StopSending
            )
                && active.is_established()
                && h3_conn.is_none()
            {
                capture.saw_h3_open = true;
                h3_conn = Some(
                    quiche::h3::Connection::with_transport(
                        active,
                        &quiche::h3::Config::new().unwrap(),
                    )
                    .unwrap(),
                );
            }

            if let Some(http3) = h3_conn.as_mut() {
                process_timeout_peer_events(
                    http3,
                    active,
                    mode,
                    &mut capture,
                    &mut close_sent,
                    &mut stall_after_request,
                    &mut stream_action_sent,
                );
            }

            if mode == Mode::ApplicationClose && close_sent {
                flush_egress(&mut socket, active, &mut out);
                emit_close_capture(mode, &capture, active);
                return;
            }

            if matches!(mode, Mode::ResetStream | Mode::StopSending) && stream_action_sent {
                flush_egress(&mut socket, active, &mut out);
                emit_close_capture(mode, &capture, active);
                return;
            }

            if !stall_after_request {
                flush_egress(&mut socket, active, &mut out);
            }

            if active.is_closed() {
                if active.is_timed_out() {
                    capture.close_trigger = "idle_timeout";
                }
                emit_close_capture(mode, &capture, active);
                return;
            }
        }

        if stall_after_request {
            if let Some(active) = conn.as_mut() {
                active.on_timeout();
                if active.is_timed_out() {
                    capture.close_trigger = "idle_timeout";
                }
                if active.is_closed() {
                    emit_close_capture(mode, &capture, active);
                    return;
                }
            }
        }
    }

    if let Some(active) = conn.as_ref() {
        emit_close_capture(mode, &capture, active);
    }
}

fn process_timeout_peer_events(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    mode: Mode,
    capture: &mut CloseCapture,
    close_sent: &mut bool,
    stall_after_request: &mut bool,
    stream_action_sent: &mut bool,
) {
    loop {
        match http3.poll(conn) {
            Ok((stream_id, quiche::h3::Event::Headers { .. })) => {
                capture.saw_request_headers = true;

                if mode == Mode::ApplicationClose && !*close_sent {
                    info!("sending deterministic QUIC application close");
                    conn.close(true, APPLICATION_CLOSE_CODE, APPLICATION_CLOSE_REASON)
                        .ok();
                    *close_sent = true;
                    capture.close_trigger = "application_close";
                    break;
                }

                if mode == Mode::ResetStream && !*stream_action_sent {
                    info!("sending deterministic HTTP/3 request-stream reset");
                    conn.stream_shutdown(stream_id, quiche::Shutdown::Write, RESET_STREAM_CODE)
                        .ok();
                    capture.stream_trigger = "reset_stream";
                    capture.reset_stream_sent = true;
                    capture.reset_stream_code = RESET_STREAM_CODE;
                    *stream_action_sent = true;
                    break;
                }

                if mode == Mode::IdleTimeout {
                    capture.close_trigger = "idle_timeout_wait";
                    *stall_after_request = true;
                }
            },

            Ok((stream_id, quiche::h3::Event::Data)) => {
                let mut scratch = [0_u8; 4096];

                loop {
                    match http3.recv_body(conn, stream_id, &mut scratch) {
                        Ok(0) => break,
                        Ok(read) => {
                            capture.saw_request_body = true;
                            capture.request_body_bytes += read;

                            if mode == Mode::StopSending && !*stream_action_sent {
                                info!("sending deterministic QUIC STOP_SENDING");
                                conn.stream_shutdown(stream_id, quiche::Shutdown::Read, STOP_SENDING_CODE)
                                    .ok();
                                capture.stream_trigger = "stop_sending";
                                capture.stop_sending_sent = true;
                                capture.stop_sending_code = STOP_SENDING_CODE;
                                *stream_action_sent = true;
                                break;
                            }
                        },
                        Err(quiche::h3::Error::Done) => break,
                        Err(_) => break,
                    }
                }

                if mode == Mode::StopSending && *stream_action_sent {
                    break;
                }
            },

            Ok((_stream_id, quiche::h3::Event::Finished)) => {
                capture.saw_request_finished = true;
            },
            Ok((_stream_id, quiche::h3::Event::Reset(_))) => (),
            Ok((_stream_id, quiche::h3::Event::PriorityUpdate)) => (),
            Ok((_goaway_id, quiche::h3::Event::GoAway)) => (),

            Err(quiche::h3::Error::Done) => break,

            Err(err) => {
                warn!("{} http3 poll failed: {err:?}", conn.trace_id());
                conn.close(false, 0x1, b"http3 failure").ok();
                break;
            },
        }
    }
}

fn emit_close_capture(mode: Mode, capture: &CloseCapture, conn: &quiche::Connection) {
    let peer_error = conn.peer_error();
    let local_error = conn.local_error();

    println!(
        "CLOSE {{\"mode\":\"{}\",\"saw_initial\":{},\"saw_established\":{},\"saw_h3_open\":{},\"saw_request_headers\":{},\"saw_request_body\":{},\"saw_request_finished\":{},\"request_body_bytes\":{},\"close_trigger\":\"{}\",\"stream_trigger\":\"{}\",\"reset_stream_sent\":{},\"reset_stream_code\":{},\"stop_sending_sent\":{},\"stop_sending_code\":{},\"is_timed_out\":{},\"is_draining\":{},\"is_closed\":{},\"peer_error_present\":{},\"peer_error_is_app\":{},\"peer_error_code\":{},\"peer_error_reason\":\"{}\",\"local_error_present\":{},\"local_error_is_app\":{},\"local_error_code\":{},\"local_error_reason\":\"{}\"}}",
        mode_name(mode),
        capture.saw_initial,
        capture.saw_established,
        capture.saw_h3_open,
        capture.saw_request_headers,
        capture.saw_request_body,
        capture.saw_request_finished,
        capture.request_body_bytes,
        capture.close_trigger,
        capture.stream_trigger,
        capture.reset_stream_sent,
        capture.reset_stream_code,
        capture.stop_sending_sent,
        capture.stop_sending_code,
        conn.is_timed_out(),
        conn.is_draining(),
        conn.is_closed(),
        peer_error.is_some(),
        peer_error.map(|err| err.is_app).unwrap_or(false),
        peer_error.map(|err| err.error_code).unwrap_or(0),
        escape_json_string(
            peer_error
                .map(|err| err.reason.as_slice())
                .unwrap_or(&[])
        ),
        local_error.is_some(),
        local_error.map(|err| err.is_app).unwrap_or(false),
        local_error.map(|err| err.error_code).unwrap_or(0),
        escape_json_string(
            local_error
                .map(|err| err.reason.as_slice())
                .unwrap_or(&[])
        ),
    );

    log_close_state(conn);
}

fn mode_name(mode: Mode) -> &'static str {
    match mode {
        Mode::HandshakeReject => "handshake_reject",
        Mode::TransportClose => "transport_close",
        Mode::ApplicationClose => "application_close",
        Mode::IdleTimeout => "idle_timeout",
        Mode::SlowResponse => "slow_response",
        Mode::SlowReader => "slow_reader",
        Mode::ResetStream => "reset_stream",
        Mode::StopSending => "stop_sending",
    }
}

fn escape_json_string(bytes: &[u8]) -> String {
    let mut escaped = String::new();

    for ch in String::from_utf8_lossy(bytes).chars() {
        match ch {
            '\\' => escaped.push_str("\\\\"),
            '"' => escaped.push_str("\\\""),
            '\n' => escaped.push_str("\\n"),
            '\r' => escaped.push_str("\\r"),
            '\t' => escaped.push_str("\\t"),
            control if control.is_control() => escaped.push('?'),
            _ => escaped.push(ch),
        }
    }

    escaped
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

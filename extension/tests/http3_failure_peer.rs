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
const FLOW_CONTROL_RECOVERY_WINDOW: usize = 4096;
const FLOW_CONTROL_RECOVERY_RESUME_DELAY_MS: u64 = 350;
const FLOW_CONTROL_RECOVERY_CAPTURE_DELAY_MS: u64 = 100;

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum Mode {
    HandshakeReject,
    TransportClose,
    ApplicationClose,
    IdleTimeout,
    SlowResponse,
    SlowReader,
    FlowControlRecovery,
    CancelObserve,
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
    flow_control_pause_observed: bool,
    flow_control_pause_bytes: usize,
    flow_control_resume_observed: bool,
    response_sent: bool,
    response_sent_after_resume: bool,
    reset_stream_sent: bool,
    reset_stream_code: u64,
    stop_sending_sent: bool,
    stop_sending_code: u64,
}

struct PendingResponse {
    stream_id: u64,
    body: Vec<u8>,
    written: usize,
    headers_sent: bool,
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
        Some("flow_control_recovery") => Mode::FlowControlRecovery,
        Some("cancel_observe") => Mode::CancelObserve,
        Some("reset_stream") => Mode::ResetStream,
        Some("stop_sending") => Mode::StopSending,
        _ => {
            eprintln!(
                "Usage: {cmd} <handshake_reject|transport_close|application_close|idle_timeout|slow_response|slow_reader|flow_control_recovery|cancel_observe|reset_stream|stop_sending> <cert> <key> [host]"
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
        Mode::FlowControlRecovery |
        Mode::CancelObserve |
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
    if matches!(
        mode,
        Mode::SlowReader | Mode::FlowControlRecovery | Mode::CancelObserve | Mode::StopSending
    ) {
        config.set_initial_max_data(FLOW_CONTROL_RECOVERY_WINDOW as u64);
    } else {
        config.set_initial_max_data(1_000_000);
    }
    config.set_initial_max_stream_data_bidi_local(1_000_000);
    if matches!(
        mode,
        Mode::SlowReader | Mode::FlowControlRecovery | Mode::CancelObserve | Mode::StopSending
    ) {
        config.set_initial_max_stream_data_bidi_remote(FLOW_CONTROL_RECOVERY_WINDOW as u64);
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
    let mut pause_body_reads = false;
    let mut flow_control_resume_deadline: Option<time::Instant> = None;
    let mut flow_control_stream_id: Option<u64> = None;
    let mut response_complete_deadline: Option<time::Instant> = None;
    let mut pending_response: Option<PendingResponse> = None;
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
        flow_control_pause_observed: false,
        flow_control_pause_bytes: 0,
        flow_control_resume_observed: false,
        response_sent: false,
        response_sent_after_resume: false,
        reset_stream_sent: false,
        reset_stream_code: 0,
        stop_sending_sent: false,
        stop_sending_code: 0,
    };
    let deadline = time::Instant::now() + time::Duration::from_secs(10);
    let mut buf = [0; 65535];
    let mut out = [0; MAX_DATAGRAM_SIZE];

    while time::Instant::now() < deadline {
        if mode == Mode::FlowControlRecovery && pause_body_reads {
            if let Some(resume_deadline) = flow_control_resume_deadline {
                if time::Instant::now() >= resume_deadline {
                    pause_body_reads = false;
                    flow_control_resume_deadline = None;
                    capture.flow_control_resume_observed = true;
                    capture.stream_trigger = "flow_control_resume";
                }
            }
        }

        let timeout = match conn.as_ref().and_then(|active| active.timeout()) {
            Some(value) => value.min(time::Duration::from_millis(100)),
            None => time::Duration::from_millis(100),
        };

        poll.poll(&mut events, Some(timeout)).unwrap();

        if events.is_empty() {
            if let Some(active) = conn.as_mut() {
                active.on_timeout();
                if let Some(http3) = h3_conn.as_mut() {
                    process_timeout_peer_events(
                        http3,
                        active,
                        mode,
                        &mut capture,
                        &mut close_sent,
                        &mut stall_after_request,
                        &mut stream_action_sent,
                        &mut pause_body_reads,
                        &mut flow_control_resume_deadline,
                        &mut flow_control_stream_id,
                        &mut pending_response,
                    );
                    flush_pending_response(
                        http3,
                        active,
                        &mut pending_response,
                        &mut capture,
                        &mut response_complete_deadline,
                    );
                }
                if active.is_timed_out()
                    && (capture.close_trigger == "none"
                        || capture.close_trigger == "idle_timeout_wait")
                {
                    capture.close_trigger = "idle_timeout";
                }
                if !stall_after_request {
                    flush_egress(&mut socket, active, &mut out);
                }
                if active.is_closed() {
                    emit_close_capture(mode, &capture, active);
                    return;
                }

                if mode == Mode::FlowControlRecovery {
                    if let Some(done_deadline) = response_complete_deadline {
                        if time::Instant::now() >= done_deadline {
                            if capture.close_trigger == "none" {
                                capture.close_trigger = "response_complete_capture";
                            }
                            emit_close_capture(mode, &capture, active);
                            return;
                        }
                    }
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
                    | Mode::FlowControlRecovery
                    | Mode::CancelObserve
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
                    &mut pause_body_reads,
                    &mut flow_control_resume_deadline,
                    &mut flow_control_stream_id,
                    &mut pending_response,
                );
                flush_pending_response(
                    http3,
                    active,
                    &mut pending_response,
                    &mut capture,
                    &mut response_complete_deadline,
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

            if mode == Mode::FlowControlRecovery {
                if let Some(done_deadline) = response_complete_deadline {
                    if time::Instant::now() >= done_deadline {
                        if capture.close_trigger == "none" {
                            capture.close_trigger = "response_complete_capture";
                        }
                        emit_close_capture(mode, &capture, active);
                        return;
                    }
                }
            }

            if active.is_closed() {
                if mode == Mode::CancelObserve {
                    capture.close_trigger = match active.peer_error() {
                        Some(error) if error.is_app => "peer_application_close",
                        Some(_) => "peer_transport_close",
                        None => "peer_closed",
                    };
                }
                if active.is_timed_out()
                    && (capture.close_trigger == "none"
                        || capture.close_trigger == "idle_timeout_wait")
                {
                    capture.close_trigger = "idle_timeout";
                }
                emit_close_capture(mode, &capture, active);
                return;
            }
        }

        if stall_after_request {
            if let Some(active) = conn.as_mut() {
                active.on_timeout();
                if active.is_timed_out()
                    && (capture.close_trigger == "none"
                        || capture.close_trigger == "idle_timeout_wait")
                {
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
    pause_body_reads: &mut bool,
    flow_control_resume_deadline: &mut Option<time::Instant>,
    flow_control_stream_id: &mut Option<u64>,
    pending_response: &mut Option<PendingResponse>,
) {
    if mode == Mode::FlowControlRecovery && !*pause_body_reads {
        if let Some(stream_id) = *flow_control_stream_id {
            drain_flow_control_recovery_body(
                http3,
                conn,
                stream_id,
                capture,
                pause_body_reads,
                flow_control_resume_deadline,
                flow_control_stream_id,
            );
        }
    }

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

                if matches!(mode, Mode::CancelObserve | Mode::FlowControlRecovery) &&
                    *pause_body_reads
                {
                    break;
                }

                if mode == Mode::FlowControlRecovery {
                    drain_flow_control_recovery_body(
                        http3,
                        conn,
                        stream_id,
                        capture,
                        pause_body_reads,
                        flow_control_resume_deadline,
                        flow_control_stream_id,
                    );

                    if *pause_body_reads {
                        break;
                    }

                    continue;
                }

                loop {
                    match http3.recv_body(conn, stream_id, &mut scratch) {
                        Ok(0) => break,
                        Ok(read) => {
                            capture.saw_request_body = true;
                            capture.request_body_bytes += read;

                            if mode == Mode::CancelObserve {
                                capture.stream_trigger = "cancel_observe";
                                *pause_body_reads = true;
                                break;
                            }

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

                if matches!(mode, Mode::CancelObserve | Mode::FlowControlRecovery) &&
                    *pause_body_reads
                {
                    break;
                }
            },

            Ok((stream_id, quiche::h3::Event::Finished)) => {
                capture.saw_request_finished = true;

                if mode == Mode::FlowControlRecovery && pending_response.is_none() {
                    *flow_control_stream_id = None;
                    *pending_response = Some(PendingResponse {
                        stream_id,
                        body: format!(
                            "flow-control-ack:{}",
                            capture.request_body_bytes
                        )
                        .into_bytes(),
                        written: 0,
                        headers_sent: false,
                    });
                }
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

fn drain_flow_control_recovery_body(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    stream_id: u64,
    capture: &mut CloseCapture,
    pause_body_reads: &mut bool,
    flow_control_resume_deadline: &mut Option<time::Instant>,
    flow_control_stream_id: &mut Option<u64>,
) {
    let mut scratch = [0_u8; 4096];
    *flow_control_stream_id = Some(stream_id);

    loop {
        match http3.recv_body(conn, stream_id, &mut scratch) {
            Ok(0) => break,
            Ok(read) => {
                capture.saw_request_body = true;
                capture.request_body_bytes += read;

                if !capture.flow_control_pause_observed &&
                    capture.request_body_bytes >= FLOW_CONTROL_RECOVERY_WINDOW
                {
                    capture.flow_control_pause_observed = true;
                    capture.flow_control_pause_bytes = capture.request_body_bytes;
                    capture.stream_trigger = "flow_control_pause";
                    *pause_body_reads = true;
                    *flow_control_resume_deadline = Some(
                        time::Instant::now() +
                            time::Duration::from_millis(
                                FLOW_CONTROL_RECOVERY_RESUME_DELAY_MS,
                            ),
                    );
                    break;
                }
            },
            Err(quiche::h3::Error::Done) => break,
            Err(_) => break,
        }
    }
}

fn flush_pending_response(
    http3: &mut quiche::h3::Connection,
    conn: &mut quiche::Connection,
    pending_response: &mut Option<PendingResponse>,
    capture: &mut CloseCapture,
    response_complete_deadline: &mut Option<time::Instant>,
) {
    let Some(response) = pending_response.as_mut() else {
        return;
    };

    if !response.headers_sent {
        let content_length = response.body.len().to_string();
        let headers = [
            quiche::h3::Header::new(b":status", b"200"),
            quiche::h3::Header::new(b"content-length", content_length.as_bytes()),
        ];

        match http3.send_response(conn, response.stream_id, &headers, response.body.is_empty()) {
            Ok(_) => {
                response.headers_sent = true;
                capture.response_sent = true;
                capture.response_sent_after_resume =
                    capture.flow_control_resume_observed;
                if response.body.is_empty() {
                    *pending_response = None;
                    *response_complete_deadline = Some(
                        time::Instant::now() +
                            time::Duration::from_millis(
                                FLOW_CONTROL_RECOVERY_CAPTURE_DELAY_MS,
                            ),
                    );
                    return;
                }
            },
            Err(quiche::h3::Error::Done) => return,
            Err(quiche::h3::Error::StreamBlocked) => return,
            Err(_) => {
                conn.close(false, 0x1, b"response failure").ok();
                return;
            },
        }
    }

    let Some(response) = pending_response.as_mut() else {
        return;
    };
    let body = &response.body[response.written..];
    if body.is_empty() {
        *pending_response = None;
        *response_complete_deadline = Some(
            time::Instant::now() +
                time::Duration::from_millis(
                    FLOW_CONTROL_RECOVERY_CAPTURE_DELAY_MS,
                ),
        );
        return;
    }

    match http3.send_body(conn, response.stream_id, body, true) {
        Ok(written) => {
            response.written += written;
            if response.written == response.body.len() {
                *pending_response = None;
                *response_complete_deadline = Some(
                    time::Instant::now() +
                        time::Duration::from_millis(
                            FLOW_CONTROL_RECOVERY_CAPTURE_DELAY_MS,
                        ),
                );
            }
        },
        Err(quiche::h3::Error::Done) => (),
        Err(quiche::h3::Error::StreamBlocked) => (),
        Err(_) => {
            conn.close(false, 0x1, b"body failure").ok();
        },
    }
}

fn emit_close_capture(mode: Mode, capture: &CloseCapture, conn: &quiche::Connection) {
    let peer_error = conn.peer_error();
    let local_error = conn.local_error();
    let close_trigger = if mode == Mode::CancelObserve && capture.close_trigger == "none" {
        match peer_error {
            Some(error) if error.is_app => "peer_application_close",
            Some(_) => "peer_transport_close",
            None => "peer_closed",
        }
    } else {
        capture.close_trigger
    };

    println!(
        "CLOSE {{\"mode\":\"{}\",\"saw_initial\":{},\"saw_established\":{},\"saw_h3_open\":{},\"saw_request_headers\":{},\"saw_request_body\":{},\"saw_request_finished\":{},\"request_body_bytes\":{},\"close_trigger\":\"{}\",\"stream_trigger\":\"{}\",\"flow_control_pause_observed\":{},\"flow_control_pause_bytes\":{},\"flow_control_resume_observed\":{},\"response_sent\":{},\"response_sent_after_resume\":{},\"reset_stream_sent\":{},\"reset_stream_code\":{},\"stop_sending_sent\":{},\"stop_sending_code\":{},\"is_timed_out\":{},\"is_draining\":{},\"is_closed\":{},\"peer_error_present\":{},\"peer_error_is_app\":{},\"peer_error_code\":{},\"peer_error_reason\":\"{}\",\"local_error_present\":{},\"local_error_is_app\":{},\"local_error_code\":{},\"local_error_reason\":\"{}\"}}",
        mode_name(mode),
        capture.saw_initial,
        capture.saw_established,
        capture.saw_h3_open,
        capture.saw_request_headers,
        capture.saw_request_body,
        capture.saw_request_finished,
        capture.request_body_bytes,
        close_trigger,
        capture.stream_trigger,
        capture.flow_control_pause_observed,
        capture.flow_control_pause_bytes,
        capture.flow_control_resume_observed,
        capture.response_sent,
        capture.response_sent_after_resume,
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
        Mode::FlowControlRecovery => "flow_control_recovery",
        Mode::CancelObserve => "cancel_observe",
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

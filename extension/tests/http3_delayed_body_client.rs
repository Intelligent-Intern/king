/* Test client that delays HTTP/3 request body completion for one-shot listener contracts. */

use quiche::h3::NameValue;

use ring::rand::SecureRandom;
use ring::rand::SystemRandom;

use std::net::SocketAddr;
use std::time::Duration;
use std::time::Instant;

const MAX_DATAGRAM_SIZE: usize = 1350;

fn main() {
    if let Err(message) = run() {
        eprintln!("{message}");
        std::process::exit(1);
    }
}

fn run() -> Result<(), String> {
    let mut args = std::env::args();
    let cmd = args
        .next()
        .unwrap_or_else(|| "king-http3-delayed-body-client".to_string());
    let url_text = args
        .next()
        .ok_or_else(|| format!("usage: {cmd} <url> <delay-ms> <body>"))?;
    let delay_ms = args
        .next()
        .ok_or_else(|| format!("usage: {cmd} <url> <delay-ms> <body>"))?
        .parse::<u64>()
        .map_err(|err| format!("invalid delay: {err}"))?;
    let body = args
        .next()
        .ok_or_else(|| format!("usage: {cmd} <url> <delay-ms> <body>"))?
        .into_bytes();

    let url = url::Url::parse(&url_text).map_err(|err| format!("invalid url: {err}"))?;
    let peer_addr = *url
        .socket_addrs(|| None)
        .map_err(|err| format!("failed to resolve peer address: {err}"))?
        .first()
        .ok_or_else(|| "no peer address resolved".to_string())?;

    let bind_addr = match peer_addr {
        SocketAddr::V4(_) => "0.0.0.0:0",
        SocketAddr::V6(_) => "[::]:0",
    };

    let mut socket = mio::net::UdpSocket::bind(
        bind_addr
            .parse()
            .map_err(|err| format!("invalid bind address: {err}"))?,
    )
    .map_err(|err| format!("failed to bind UDP socket: {err}"))?;

    let mut poll = mio::Poll::new().map_err(|err| format!("failed to create poll: {err}"))?;
    let mut events = mio::Events::with_capacity(1024);

    poll.registry()
        .register(&mut socket, mio::Token(0), mio::Interest::READABLE)
        .map_err(|err| format!("failed to register socket: {err}"))?;

    let mut config =
        quiche::Config::new(quiche::PROTOCOL_VERSION).map_err(|err| format!("{err:?}"))?;
    config.verify_peer(false);
    config
        .set_application_protos(quiche::h3::APPLICATION_PROTOCOL)
        .map_err(|err| format!("failed to set ALPN: {err:?}"))?;
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

    let mut scid = [0_u8; quiche::MAX_CONN_ID_LEN];
    SystemRandom::new()
        .fill(&mut scid)
        .map_err(|_| "failed to generate QUIC source connection id".to_string())?;
    let scid = quiche::ConnectionId::from_ref(&scid);

    let local_addr = socket
        .local_addr()
        .map_err(|err| format!("failed to get local address: {err}"))?;
    let mut conn = quiche::connect(url.domain(), &scid, local_addr, peer_addr, &mut config)
        .map_err(|err| format!("failed to start QUIC connection: {err:?}"))?;

    let mut out = [0_u8; MAX_DATAGRAM_SIZE];
    let mut buf = [0_u8; 65535];

    let (written, send_info) = conn
        .send(&mut out)
        .map_err(|err| format!("initial QUIC send failed: {err:?}"))?;
    socket
        .send_to(&out[..written], send_info.to)
        .map_err(|err| format!("initial UDP send failed: {err}"))?;

    let h3_config = quiche::h3::Config::new()
        .map_err(|err| format!("failed to create HTTP/3 config: {err:?}"))?;

    let mut h3_conn: Option<quiche::h3::Connection> = None;
    let mut headers_sent = false;
    let mut body_sent = false;
    let mut body_offset = 0_usize;
    let mut request_stream_id = 0_u64;
    let mut headers_sent_at = Instant::now();
    let body_deadline = Duration::from_millis(delay_ms);

    let mut response_status = 0_u16;
    let mut response_body = Vec::new();
    let mut response_finished = false;
    let mut early_response = false;

    let authority = match url.port() {
        Some(port) => format!(
            "{}:{}",
            url.host_str().ok_or_else(|| "URL host missing".to_string())?,
            port
        ),
        None => url
            .host_str()
            .ok_or_else(|| "URL host missing".to_string())?
            .to_string(),
    };

    let mut path = url.path().to_string();
    if let Some(query) = url.query() {
        path.push('?');
        path.push_str(query);
    }
    if path.is_empty() {
        path.push('/');
    }

    let content_length = body.len().to_string();
    let request = vec![
        quiche::h3::Header::new(b":method", b"POST"),
        quiche::h3::Header::new(b":scheme", url.scheme().as_bytes()),
        quiche::h3::Header::new(b":authority", authority.as_bytes()),
        quiche::h3::Header::new(b":path", path.as_bytes()),
        quiche::h3::Header::new(b"user-agent", b"king-http3-delayed-body-client"),
        quiche::h3::Header::new(b"content-length", content_length.as_bytes()),
        quiche::h3::Header::new(b"x-mode", b"delayed-body"),
    ];

    let deadline = Instant::now() + Duration::from_secs(15);

    while Instant::now() < deadline {
        let poll_timeout = match conn.timeout() {
            Some(timeout) => {
                if headers_sent && !body_sent {
                    let remaining = body_deadline.saturating_sub(headers_sent_at.elapsed());
                    Some(timeout.min(remaining.min(Duration::from_millis(50))))
                } else {
                    Some(timeout.min(Duration::from_millis(50)))
                }
            },
            None => Some(Duration::from_millis(50)),
        };

        poll.poll(&mut events, poll_timeout)
            .map_err(|err| format!("poll failed: {err}"))?;

        if events.is_empty() {
            conn.on_timeout();
        }

        'read: loop {
            if events.is_empty() {
                break 'read;
            }

            let (len, from) = match socket.recv_from(&mut buf) {
                Ok(value) => value,
                Err(err) => {
                    if err.kind() == std::io::ErrorKind::WouldBlock {
                        break 'read;
                    }

                    return Err(format!("recv_from failed: {err}"));
                },
            };

            let recv_info = quiche::RecvInfo {
                to: local_addr,
                from,
            };

            match conn.recv(&mut buf[..len], recv_info) {
                Ok(_) => (),
                Err(quiche::Error::Done) => break 'read,
                Err(err) => return Err(format!("QUIC recv failed: {err:?}")),
            }
        }

        if conn.is_closed() && response_status == 0 {
            return Err("connection closed before any HTTP/3 response arrived".to_string());
        }

        if conn.is_established() && h3_conn.is_none() {
            h3_conn = Some(
                quiche::h3::Connection::with_transport(&mut conn, &h3_config)
                    .map_err(|err| format!("failed to create HTTP/3 connection: {err:?}"))?,
            );
        }

        if let Some(h3) = h3_conn.as_mut() {
            if !headers_sent {
                request_stream_id = h3
                    .send_request(&mut conn, &request, false)
                    .map_err(|err| format!("send_request failed: {err:?}"))?;
                headers_sent = true;
                headers_sent_at = Instant::now();
            }

            if headers_sent && !body_sent && headers_sent_at.elapsed() >= body_deadline {
                let sent = h3
                    .send_body(&mut conn, request_stream_id, &body[body_offset..], true)
                    .map_err(|err| format!("send_body failed: {err:?}"))?;
                body_offset += sent;
                if body_offset == body.len() {
                    body_sent = true;
                }
            }

            loop {
                match h3.poll(&mut conn) {
                    Ok((_stream_id, quiche::h3::Event::Headers { list, .. })) => {
                        if !body_sent {
                            early_response = true;
                        }

                        for header in &list {
                            if header.name() == b":status" {
                                response_status = std::str::from_utf8(header.value())
                                    .map_err(|err| format!("invalid response status bytes: {err}"))?
                                    .parse::<u16>()
                                    .map_err(|err| format!("invalid response status: {err}"))?;
                            }
                        }
                    },
                    Ok((stream_id, quiche::h3::Event::Data)) => {
                        if !body_sent {
                            early_response = true;
                        }

                        loop {
                            match h3.recv_body(&mut conn, stream_id, &mut buf) {
                                Ok(read) => response_body.extend_from_slice(&buf[..read]),
                                Err(quiche::h3::Error::Done) => break,
                                Err(err) => return Err(format!("recv_body failed: {err:?}")),
                            }
                        }
                    },
                    Ok((_stream_id, quiche::h3::Event::Finished)) => {
                        if !body_sent {
                            early_response = true;
                        }

                        response_finished = true;
                        conn.close(true, 0x100, b"done").ok();
                    },
                    Ok((_stream_id, quiche::h3::Event::Reset(err))) => {
                        return Err(format!("request stream reset by peer: {err:?}"));
                    },
                    Ok((_stream_id, quiche::h3::Event::PriorityUpdate)) => unreachable!(),
                    Ok((_goaway_id, quiche::h3::Event::GoAway)) => (),
                    Err(quiche::h3::Error::Done) => break,
                    Err(err) => return Err(format!("HTTP/3 polling failed: {err:?}")),
                }
            }
        }

        flush_egress(&mut socket, &mut conn, &mut out)?;

        if response_finished {
            println!("STATUS {}", response_status);
            println!("EARLY_RESPONSE {}", if early_response { 1 } else { 0 });
            println!("BODY {}", String::from_utf8_lossy(&response_body));
            return Ok(());
        }
    }

    Err("timed out waiting for delayed-body HTTP/3 response".to_string())
}

fn flush_egress(
    socket: &mut mio::net::UdpSocket,
    conn: &mut quiche::Connection,
    out: &mut [u8; MAX_DATAGRAM_SIZE],
) -> Result<(), String> {
    loop {
        let (written, send_info) = match conn.send(out) {
            Ok(value) => value,
            Err(quiche::Error::Done) => break,
            Err(err) => return Err(format!("QUIC send failed: {err:?}")),
        };

        socket
            .send_to(&out[..written], send_info.to)
            .map_err(|err| format!("UDP send failed: {err}"))?;
    }

    Ok(())
}

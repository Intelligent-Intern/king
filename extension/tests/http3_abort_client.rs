/* Test client that aborts an HTTP/3 request mid-flight for server cancel contracts. */

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
        .unwrap_or_else(|| "king-http3-abort-client".to_string());
    let url_text = args
        .next()
        .ok_or_else(|| format!("usage: {cmd} <url> <delay-ms>"))?;
    let delay_ms = args
        .next()
        .ok_or_else(|| format!("usage: {cmd} <url> <delay-ms>"))?
        .parse::<u64>()
        .map_err(|err| format!("invalid delay: {err}"))?;

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
    let mut request_sent = false;
    let mut request_stream_id = 0_u64;
    let mut request_sent_at: Option<Instant> = None;
    let close_sent = false;
    let close_delay = Duration::from_millis(delay_ms);

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

    let request = vec![
        quiche::h3::Header::new(b":method", b"GET"),
        quiche::h3::Header::new(b":scheme", url.scheme().as_bytes()),
        quiche::h3::Header::new(b":authority", authority.as_bytes()),
        quiche::h3::Header::new(b":path", path.as_bytes()),
        quiche::h3::Header::new(b"user-agent", b"king-http3-abort-client"),
        quiche::h3::Header::new(b"x-mode", b"cancel-callback"),
    ];

    let deadline = Instant::now() + Duration::from_secs(15);

    while Instant::now() < deadline {
        let poll_timeout = match conn.timeout() {
            Some(timeout) => timeout.min(Duration::from_millis(50)),
            None => Duration::from_millis(50),
        };

        poll.poll(&mut events, Some(poll_timeout))
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

        if conn.is_established() && h3_conn.is_none() {
            h3_conn = Some(
                quiche::h3::Connection::with_transport(&mut conn, &h3_config)
                    .map_err(|err| format!("failed to create HTTP/3 connection: {err:?}"))?,
            );
        }

        if let Some(h3) = h3_conn.as_mut() {
            if !request_sent {
                request_stream_id = h3
                    .send_request(&mut conn, &request, true)
                    .map_err(|err| format!("send_request failed: {err:?}"))?;
                request_sent = true;
                request_sent_at = Some(Instant::now());
            }

            loop {
                match h3.poll(&mut conn) {
                    Ok((_stream_id, quiche::h3::Event::Headers { .. })) => (),
                    Ok((_stream_id, quiche::h3::Event::Data)) => (),
                    Ok((_stream_id, quiche::h3::Event::Finished)) => (),
                    Ok((_stream_id, quiche::h3::Event::Reset(_))) => (),
                    Ok((_stream_id, quiche::h3::Event::PriorityUpdate)) => unreachable!(),
                    Ok((_goaway_id, quiche::h3::Event::GoAway)) => (),
                    Err(quiche::h3::Error::Done) => break,
                    Err(err) => return Err(format!("HTTP/3 polling failed: {err:?}")),
                }
            }
        }

        flush_egress(&mut socket, &mut conn, &mut out)?;

        if request_sent && !close_sent && request_sent_at.unwrap().elapsed() >= close_delay {
            conn.close(true, 0x100, b"client abort")
                .map_err(|err| format!("failed to close client QUIC connection: {err:?}"))?;
            flush_egress(&mut socket, &mut conn, &mut out)?;
            println!("ABORTED {}", request_stream_id);
            return Ok(());
        }
    }

    Err("timed out waiting to abort the HTTP/3 request".to_string())
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

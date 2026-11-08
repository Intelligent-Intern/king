/* Local HTTP/2 fixture with independently delayed streams, including TLS. */
const http2 = require('node:http2');
const fs = require('node:fs');
const secure = process.argv.length > 2;
const server = secure ? http2.createSecureServer({
    cert: fs.readFileSync(process.argv[2]), key: fs.readFileSync(process.argv[3]), allowHTTP1: true,
}) : http2.createServer();
server.on('request', (req, res) => {
    if (req.httpVersionMajor !== 1) return;
    res.writeHead(200, {'content-type':'application/json'});
    res.end(JSON.stringify({protocol:'http/1.1'}));
});
let nextSession = 0;
const states = new WeakMap();
server.on('session', session => {
    states.set(session, {id: ++nextSession, active: 0, peak: 0});
    session.on('error', () => {});
});
server.on('stream', (stream, headers) => {
    const state = states.get(stream.session);
    state.peak = Math.max(state.peak, ++state.active);
    const path = headers[':path'];
    const query = new URL(path, 'http://localhost').searchParams;
    const chunks = [];
    stream.on('error', () => {});
    stream.on('data', chunk => chunks.push(chunk));
    stream.on('end', () => setTimeout(() => {
        --state.active;
        if (stream.destroyed || stream.closed) return;
        stream.respond({':status': Number(query.get('status') || 200), 'content-type':'application/json'});
        if (query.has('big')) stream.end(Buffer.alloc(9 * 1024 * 1024, 65));
        else stream.end(JSON.stringify({session:state.id, peak:state.peak, path, body:Buffer.concat(chunks).toString('base64')}));
    }, Math.min(2000, Math.max(0, Number(query.get('ms') || 0)))));
});
server.on('error', error => { console.error(error); process.exit(2); });
server.listen(0, '127.0.0.1', () => console.log(server.address().port));

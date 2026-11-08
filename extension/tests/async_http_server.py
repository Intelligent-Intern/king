"""Local concurrent HTTP fixture; never contacts an external endpoint."""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlsplit, parse_qs
import json, threading, time
lock = threading.Lock()
active = 0
peak = 0
class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def do_GET(self):
        global active, peak
        query = parse_qs(urlsplit(self.path).query)
        delay = min(3000, max(0, int(query.get('ms', ['0'])[0]))) / 1000
        with lock:
            active += 1
            peak = max(peak, active)
        try:
            time.sleep(delay)
            body = json.dumps({'path': self.path, 'peak': peak}).encode()
            self.send_response(200)
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass
        finally:
            with lock: active -= 1
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, *args): pass
server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
server.daemon_threads = True
print(server.server_address[1], flush=True)
server.serve_forever()

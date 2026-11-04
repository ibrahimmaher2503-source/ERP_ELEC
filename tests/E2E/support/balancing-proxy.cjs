'use strict';

// PHP's built-in server serves one connection at a time, so an idle browser keep-alive or speculative
// preconnect can stall every later request. This proxy closes idle sockets itself and spreads the
// requests over several PHP workers. Usage: node balancing-proxy.cjs <listenPort> <upstreamPort>...
const http = require('node:http');

const [listenPort, ...upstreams] = process.argv.slice(2).map(Number);
if (!listenPort || upstreams.length === 0) throw new Error('listen port and upstream ports are required');
let next = 0;

const server = http.createServer((request, response) => {
  const target = upstreams[next++ % upstreams.length];
  const headers = { ...request.headers, connection: 'close' };
  const upstream = http.request({ host: '127.0.0.1', port: target, method: request.method, path: request.url, headers, agent: false }, reply => {
    response.writeHead(reply.statusCode, reply.headers);
    reply.pipe(response);
  });
  upstream.on('error', () => { if (!response.headersSent) response.writeHead(502); response.end(); });
  request.on('aborted', () => upstream.destroy());
  request.pipe(upstream);
});
server.keepAliveTimeout = 1_000;
server.headersTimeout = 10_000;
server.listen(listenPort, '127.0.0.1');

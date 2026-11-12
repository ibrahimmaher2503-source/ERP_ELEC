'use strict';

async function closeOwnedBrowserServer(server, timeoutMs = 10_000) {
  const ownedProcess = server.process();
  let timer;
  try {
    await Promise.race([server.close(), new Promise((_, reject) => {
      timer = setTimeout(() => {
        if (ownedProcess.exitCode === null && !ownedProcess.killed) ownedProcess.kill('SIGKILL');
        reject(new Error(`Owned browser cleanup exceeded ${timeoutMs}ms.`));
      }, timeoutMs);
    })]);
  } finally { clearTimeout(timer); }
}

module.exports = { closeOwnedBrowserServer };

'use strict';

const fs = require('node:fs');
const path = require('node:path');

class FailureOnlyReporter {
  onBegin(config, suite) {
    this.started = Date.now();
    this.total = suite.allTests().length;
    this.suite = suite;
  }

  // Written as each test ends, so a result survives a browser that never closes on Windows.
  onTestEnd(test, result) {
    this.ended = this.ended || [];
    this.ended.push({ title: test.title, status: result.status, expected: test.outcome() === 'expected' || test.outcome() === 'skipped',
      message: result.error ? String(result.error.message || '').replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 400) : null });
    const target = path.resolve(process.cwd(), '.tmp/e2e-automation', `playwright-partial-${process.env.E2E_RUN_TOKEN || 'unknown'}.json`);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, JSON.stringify({ total: this.total, tests: this.ended }, null, 2) + '\n', { encoding: 'utf8' });
  }

  onEnd(result) {
    const tests = this.suite.allTests();
    const outcomes = tests.map(test => test.outcome());
    const failed = tests.flatMap(test => test.outcome() === 'unexpected'
      ? [{ title: test.title, file: test.location.file, message: (test.results.at(-1)?.error?.message || 'unexpected test failure').replace(/https?:\/\/[^\s)]+/g, '<url>').replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 700), attachments: test.results.at(-1)?.attachments || [] }]
      : []);
    const report = {
      status: this.globalError ? 'failed' : result.status,
      total: this.total,
      passed: outcomes.filter(status => status === 'expected').length,
      failed: failed.length,
      skipped: outcomes.filter(status => status === 'skipped').length,
      flaky: outcomes.filter(status => status === 'flaky').length,
      duration_ms: Date.now() - this.started,
      gaps: [],
      failures: failed.map(item => ({ title: item.title, file: item.file, message: item.message.slice(0, 700) })),
      global_errors: this.globalError ? [this.globalError] : [],
      artifacts: failed.flatMap(item => item.attachments.filter(a => a.path).map(a => path.relative(process.cwd(), a.path))),
    };
    const target = path.resolve(process.cwd(), '.tmp/e2e-automation', `playwright-result-${process.env.E2E_RUN_TOKEN || 'unknown'}.json`);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, JSON.stringify(report, null, 2) + '\n', { encoding: 'utf8' });
    this.result = report;
  }

  onError(error) { this.globalError = String(error.message || error).replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 400); }
}

module.exports = FailureOnlyReporter;

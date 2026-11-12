'use strict';

const assert = require('node:assert/strict');
const { successful } = require('./run-owned-check.cjs');

assert.equal(successful({ code: 0, stdout: 'PASS fixture\nDONE\n', stderr: '' }, 'DONE'), true);
assert.equal(successful({ code: 0, stdout: 'PHP Fatal error: fixture\nDONE\n', stderr: '' }, 'DONE'), false);
assert.equal(successful({ code: 0, stdout: 'In OptimizationRegressionDatabaseGuard.php line 169:\nRefusing fixture writes.\nDONE\n', stderr: '' }, 'DONE'), false);
assert.equal(successful({ code: 0, stdout: 'PASS fixture\n', stderr: '' }, 'DONE'), false);
assert.equal(successful({ code: 1, stdout: 'DONE\n', stderr: '' }, 'DONE'), false);
process.stdout.write('PASS owned check runner selfcheck (success, exception, missing marker, nonzero exit)\n');

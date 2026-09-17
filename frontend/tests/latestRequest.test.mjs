import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createLatestRequest } from '../src/utils/latestRequest.js'

function deferred() {
 let resolve, reject
 const promise = new Promise((yes, no) => { resolve = yes; reject = no })
 return { promise, resolve, reject }
}

function fixture() {
 const calls = [], results = [], errors = []
 const state = { loading: false, settled: 0 }
 const runner = createLatestRequest()
 function run(key) {
  return runner.run(key, {
   onStart() { state.loading = true },
   request(signal) { const wait = deferred(); calls.push({ key, signal, ...wait }); return wait.promise },
   onResult(result) { results.push(result) },
   onError(error) { errors.push(error.message) },
   onSettled() { state.loading = false; state.settled++ }
  })
 }
 return { runner, run, calls, results, errors, state }
}

test('a late load-more response cannot overwrite a newer search or clear its loading state', async () => {
 const f = fixture(), older = f.run('discover-page-2')
 await Promise.resolve()
 const current = f.run('new-query')
 await Promise.resolve()
 assert.equal(f.calls[0].signal.aborted, true)
 f.calls[0].resolve('old discovery'); await older
 assert.deepEqual(f.results, []); assert.equal(f.state.loading, true); assert.equal(f.state.settled, 0)
 f.calls[1].resolve('new search'); await current
 assert.deepEqual(f.results, ['new search']); assert.equal(f.state.loading, false); assert.equal(f.state.settled, 1)
})

test('repeated identical pending submits share one request and result', async () => {
 const f = fixture(), first = f.run('query'), second = f.run('query')
 assert.equal(first, second)
 await Promise.resolve(); assert.equal(f.calls.length, 1)
 f.calls[0].resolve('result'); await second
 assert.deepEqual(f.results, ['result']); assert.equal(f.state.settled, 1)
})

test('superseded failures are ignored; current errors clear loading and can be retried', async () => {
 const f = fixture(), older = f.run('old')
 await Promise.resolve(); const current = f.run('new'); await Promise.resolve()
 f.calls[0].reject(Error('obsolete error')); await older
 assert.deepEqual(f.errors, []); assert.equal(f.state.loading, true)
 f.calls[1].reject(Error('network failed')); await current
 assert.deepEqual(f.errors, ['network failed']); assert.equal(f.state.loading, false)
 const retry = f.run('new'); await Promise.resolve(); f.calls[2].resolve('retried'); await retry
 assert.deepEqual(f.results, ['retried'])
})

test('unmount aborts pending work and forbids later UI changes or new requests', async () => {
 const f = fixture(), pending = f.run('pending')
 await Promise.resolve(); f.runner.dispose()
 assert.equal(f.calls[0].signal.aborted, true)
 f.calls[0].resolve('late'); await pending; await f.run('after unmount')
 assert.deepEqual(f.results, []); assert.equal(f.state.settled, 0); assert.equal(f.calls.length, 1)
})

test('immediate supersession does not even dispatch the obsolete request', async () => {
 const f = fixture(), old = f.run('old'), current = f.run('new')
 await Promise.resolve(); assert.deepEqual(f.calls.map(x => x.key), ['new'])
 f.calls[0].resolve('new'); await Promise.all([old, current]); assert.deepEqual(f.results, ['new'])
})

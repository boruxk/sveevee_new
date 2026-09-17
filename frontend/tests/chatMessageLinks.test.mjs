import assert from 'node:assert/strict'
import { test } from 'node:test'
import { chatMessageSegments } from '../src/utils/chatMessageLinks.js'

test('HTTP, HTTPS and www links become separate links while line breaks and plain text remain intact', () => {
	assert.deepEqual(chatMessageSegments('Hello\nhttp://example.test/a and www.example.test\nhttps://example.test/b'), [
		{ type: 'text', text: 'Hello\n' },
		{ type: 'link', text: 'http://example.test/a', href: 'http://example.test/a' },
		{ type: 'text', text: ' and ' },
		{ type: 'link', text: 'www.example.test', href: 'https://www.example.test' },
		{ type: 'text', text: '\n' },
		{ type: 'link', text: 'https://example.test/b', href: 'https://example.test/b' }
	])
})

test('Hebrew is readable while the original encoded destination and reserved escapes are preserved', () => {
	const href = 'https://sveevee.co.il/pages/%D7%A9%D7%9C%D7%95%D7%9D?q=%D7%AA%D7%9C%20%D7%90%D7%91%D7%99%D7%91%26x%3D1'
	assert.deepEqual(chatMessageSegments(`שלום ${href}`), [
		{ type: 'text', text: 'שלום ' },
		{ type: 'link', text: 'https://sveevee.co.il/pages/שלום?q=תל אביב%26x%3D1', href }
	])
	assert.deepEqual(chatMessageSegments('https://example.test/צעצועים')[0], { type: 'link', text: 'https://example.test/צעצועים', href: 'https://example.test/צעצועים' })
})

test('sentence punctuation and wrapping brackets stay outside links but balanced URL brackets remain', () => {
	assert.deepEqual(chatMessageSegments('See (https://example.test/wiki/Toy_(game)). www.example.test!'), [
		{ type: 'text', text: 'See (' },
		{ type: 'link', text: 'https://example.test/wiki/Toy_(game)', href: 'https://example.test/wiki/Toy_(game)' },
		{ type: 'text', text: '). ' },
		{ type: 'link', text: 'www.example.test', href: 'https://www.example.test' },
		{ type: 'text', text: '!' }
	])
	assert.equal(chatMessageSegments('https://example.test/?q=1&next=(two)#end')[0].href, 'https://example.test/?q=1&next=(two)#end')
})

test('malformed encoded paths do not throw or prevent other valid Unicode escapes from being displayed', () => {
	const href = 'https://example.test/%ZZ/%D7%A9%D7%9C%D7%95%D7%9D/%E0%A4'
	assert.deepEqual(chatMessageSegments(href), [{ type: 'link', text: 'https://example.test/%ZZ/שלום/%E0%A4', href }])
	assert.deepEqual(chatMessageSegments('https://%ZZ/'), [{ type: 'text', text: 'https://%ZZ/' }])
})

test('unsafe schemes, partial URL schemes and email-domain fragments remain plain text', () => {
	for (const body of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'javascript:https://example.test', 'ftp://www.example.test/file', 'person@www.example.test', 'https://', 'https://example.test\\@evil.test']) {
		assert.deepEqual(chatMessageSegments(body), [{ type: 'text', text: body }])
	}
})

test('HTML remains literal text and encoded bidi/control characters are never decoded for display', () => {
	assert.deepEqual(chatMessageSegments('<img src=x onerror=alert(1)>'), [{ type: 'text', text: '<img src=x onerror=alert(1)>' }])
	const href = 'https://example.test/%0A%E2%80%AE%D7%A9'
	assert.deepEqual(chatMessageSegments(href), [{ type: 'link', text: 'https://example.test/%0A%E2%80%AEש', href }])
	assert.deepEqual(chatMessageSegments(null), [])
})

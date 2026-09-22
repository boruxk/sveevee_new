// Keep in sync with NoGuestChatLinks.php; both use the same link-policy fixtures.
const SCHEME = /(?:[a-z][a-z0-9+.-]{1,31}\s*:\s*[/\\]{2}|(?:https?|mailto|tel|sms|javascript|data)\s*:|(?<![\p{L}\p{N}_@.])www\s*\.)/iu
const DOMAIN = /(?<![\p{L}\p{N}_@.-])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})(?![\p{L}\p{N}_-])/iu
const ADDRESS = /(?<![\p{L}\p{N}_@.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![\p{L}\p{N}_.])|\[[a-f0-9]*:[a-f0-9:]+\]/iu

export function containsGuestChatLink(body) {
	let text = String(body ?? '')
	for (let i = 0; i < 2; i++) {
		text = text.replace(/(?:%[a-f0-9]{2})+/gi, (encoded) => {
			try { return decodeURIComponent(encoded) } catch { return encoded }
		})
	}
	text = text.replace(/[\uff01-\uff5e]/gu, (character) => String.fromCharCode(character.charCodeAt(0) - 0xfee0))
		.replace(/[\u3002\uff61]/gu, '.')
		.replace(/\p{Cf}/gu, '')
	return SCHEME.test(text) || DOMAIN.test(text) || ADDRESS.test(text)
}

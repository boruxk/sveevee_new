import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { baseParse, NodeTypes } from '@vue/compiler-dom'
import { parse as parseSfc } from '@vue/compiler-sfc'
import en from '../src/i18n/messages/en.js'
import fr from '../src/i18n/messages/fr.js'
import he from '../src/i18n/messages/he.js'
import ru from '../src/i18n/messages/ru.js'
import { disclaimers, termsConditions } from '../src/constants/legalDocuments.js'
import { privacyPolicies } from '../src/constants/privacyPolicy.js'

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const sourceRoot = path.join(projectRoot, 'src')
const localeMessagesRoot = path.join(sourceRoot, 'i18n', 'messages')
const locales = { en, he, ru, fr }
const localeNames = Object.keys(locales)
const issues = []

const visibleAttributeNames = new Set([
	'alt',
	'aria-label',
	'caption',
	'hint',
	'label',
	'placeholder',
	'title'
])

const sharedTerms = new Set([
	'Facebook',
	'Google',
	'HH:MM',
	'Instagram',
	'Sveevee',
	'Telegram',
	'TikTok',
	'WhatsApp',
	'reCAPTCHA',
	'sveevee'
])

function report(scope, message) {
	issues.push(`${scope}: ${message}`)
}

function valueKind(value) {
	if (Array.isArray(value)) return 'array'
	if (value === null) return 'null'
	return typeof value
}

function placeholders(value) {
	return [...String(value).matchAll(/\{([A-Za-z0-9_]+)\}/g)]
		.map((match) => match[1])
		.sort()
}

function compareStructure(reference, candidate, scope, currentPath = '') {
	const referenceKind = valueKind(reference)
	const candidateKind = valueKind(candidate)

	if (referenceKind !== candidateKind) {
		report(scope, `${currentPath || '<root>'} is ${candidateKind}; expected ${referenceKind}`)
		return
	}

	if (referenceKind === 'array') {
		if (reference.length !== candidate.length) {
			report(scope, `${currentPath} has ${candidate.length} entries; expected ${reference.length}`)
		}

		for (let index = 0; index < Math.min(reference.length, candidate.length); index += 1) {
			compareStructure(reference[index], candidate[index], scope, `${currentPath}.${index}`)
		}
		return
	}

	if (referenceKind === 'object') {
		const referenceKeys = Object.keys(reference)
		const candidateKeys = Object.keys(candidate)

		for (const key of referenceKeys) {
			const childPath = currentPath ? `${currentPath}.${key}` : key
			if (!(key in candidate)) {
				report(scope, `missing key ${childPath}`)
				continue
			}
			compareStructure(reference[key], candidate[key], scope, childPath)
		}

		for (const key of candidateKeys) {
			if (!(key in reference)) {
				const childPath = currentPath ? `${currentPath}.${key}` : key
				report(scope, `unexpected key ${childPath}`)
			}
		}
		return
	}

	if (referenceKind === 'string') {
		if (reference.trim() && !candidate.trim()) {
			report(scope, `${currentPath} is empty`)
		}

		const expectedPlaceholders = placeholders(reference)
		const actualPlaceholders = placeholders(candidate)
		if (expectedPlaceholders.join('|') !== actualPlaceholders.join('|')) {
			report(
				scope,
				`${currentPath} uses {${actualPlaceholders.join(', ')}}; expected {${expectedPlaceholders.join(', ')}}`
			)
		}
	}
}

function flatten(value, currentPath = '', output = new Map()) {
	if (Array.isArray(value)) {
		value.forEach((entry, index) => flatten(entry, `${currentPath}.${index}`, output))
		return output
	}

	if (value && typeof value === 'object') {
		for (const [key, entry] of Object.entries(value)) {
			flatten(entry, currentPath ? `${currentPath}.${key}` : key, output)
		}
		return output
	}

	output.set(currentPath, value)
	return output
}

function getByPath(value, dottedPath) {
	return dottedPath.split('.').reduce((current, key) => current?.[key], value)
}

function isSharedTranslationValue(translationPath, value) {
	if (translationPath.endsWith('.icon')) return true
	if (sharedTerms.has(value)) return true
	if (/^(?:https?:\/\/|mailto:)/i.test(value)) return true
	if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return true
	return false
}

function auditLikelyUntranslatedValues() {
	const english = flatten(en)

	for (const localeName of ['he', 'ru']) {
		const translated = flatten(locales[localeName])
		for (const [translationPath, value] of translated) {
			if (typeof value !== 'string' || !/[A-Za-z]/.test(value)) continue
			if (isSharedTranslationValue(translationPath, value)) continue
			if (value === english.get(translationPath)) {
				report(localeName, `${translationPath} still matches English: "${value}"`)
			}
		}
	}

	const french = flatten(fr)
	for (const [translationPath, value] of french) {
		if (typeof value !== 'string' || !/\s/.test(value)) continue
		if (isSharedTranslationValue(translationPath, value)) continue
		if (value === english.get(translationPath)) {
			report('fr', `${translationPath} still matches English: "${value}"`)
		}
	}
}

async function collectSourceFiles(directory) {
	const entries = await fs.readdir(directory, { withFileTypes: true })
	const files = []

	for (const entry of entries) {
		const absolutePath = path.join(directory, entry.name)
		if (entry.isDirectory()) {
			files.push(...await collectSourceFiles(absolutePath))
		} else if (/\.(?:js|vue)$/.test(entry.name)) {
			files.push(absolutePath)
		}
	}

	return files
}

function displayPath(filePath) {
	return path.relative(projectRoot, filePath).replaceAll('\\', '/')
}

function lineForOffset(content, offset) {
	return content.slice(0, offset).split('\n').length
}

function isAllowedHardcodedText(value) {
	const normalized = value.replace(/\s+/g, ' ').trim()
	if (!normalized || !/[\p{L}]/u.test(normalized)) return true
	if (sharedTerms.has(normalized)) return true
	if (normalized === 'ID') return true
	return false
}

function auditTemplate(filePath, content) {
	const parsed = parseSfc(content, { filename: filePath })
	for (const error of parsed.errors) {
		report(displayPath(filePath), `Vue parse error: ${String(error)}`)
	}

	const template = parsed.descriptor.template
	if (!template) return

	let ast
	try {
		ast = baseParse(template.content)
	} catch (error) {
		report(displayPath(filePath), `template parse error: ${error.message}`)
		return
	}

	const templateStartLine = lineForOffset(content, template.loc.start.offset)
	const fileLine = (node) => templateStartLine + node.loc.start.line - 1

	function walk(node) {
		if (node.type === NodeTypes.TEXT) {
			const text = node.content.replace(/\s+/g, ' ').trim()
			if (!isAllowedHardcodedText(text)) {
				report(displayPath(filePath), `hardcoded template text at line ${fileLine(node)}: "${text}"`)
			}
		}

		if (node.type === NodeTypes.ELEMENT) {
			for (const prop of node.props) {
				if (prop.type !== NodeTypes.ATTRIBUTE || !prop.value) continue
				if (!visibleAttributeNames.has(prop.name)) continue
				if (isAllowedHardcodedText(prop.value.content)) continue
				report(
					displayPath(filePath),
					`hardcoded ${prop.name} at line ${fileLine(prop)}: "${prop.value.content}"`
				)
			}
		}

		for (const child of node.children || []) walk(child)
		if (node.type === NodeTypes.IF) {
			for (const branch of node.branches) walk(branch)
		}
		if (node.type === NodeTypes.FOR && node.children) {
			for (const child of node.children) walk(child)
		}
	}

	walk(ast)
}

function auditStaticTranslationKeys(filePath, content) {
	const translationCall = /(?:^|[^\w$])(?:\$?t|te)\(\s*(['"`])([^'"`$]+)\1/gm
	for (const match of content.matchAll(translationCall)) {
		const key = match[2]
		for (const localeName of localeNames) {
			if (getByPath(locales[localeName], key) === undefined) {
				const line = lineForOffset(content, match.index)
				report(displayPath(filePath), `missing ${localeName} translation "${key}" used at line ${line}`)
			}
		}
	}

	if (/response\?*\.data\?*\.message/.test(content)) {
		report(displayPath(filePath), 'raw API response message can bypass localization')
	}

	if (/\.(?:toLocaleDateString|toLocaleTimeString)\(\s*\)/.test(content)) {
		report(displayPath(filePath), 'date/time formatting relies on the browser locale instead of the selected app locale')
	}

	if (/`\$\{[^}]+\}\s+logo`/.test(content)) {
		report(displayPath(filePath), 'dynamic logo alt text is hardcoded in English')
	}
}

function auditUnicodeEscapes(filePath, content) {
	if (!filePath.startsWith(localeMessagesRoot)) return

	for (const match of content.matchAll(/\\u[0-9a-f]{4}/gi)) {
		report(
			displayPath(filePath),
			`Unicode escape at line ${lineForOffset(content, match.index)}; use the normal UTF-8 character`
		)
	}
}

for (const localeName of localeNames.filter((name) => name !== 'en')) {
	compareStructure(en, locales[localeName], `messages/${localeName}`)
}

for (const [documentName, documents] of Object.entries({
	privacy: privacyPolicies,
	terms: termsConditions,
	disclaimer: disclaimers
})) {
	for (const localeName of localeNames.filter((name) => name !== 'en')) {
		compareStructure(documents.en, documents[localeName], `${documentName}/${localeName}`)
	}
}

auditLikelyUntranslatedValues()

const sourceFiles = await collectSourceFiles(sourceRoot)
for (const filePath of sourceFiles) {
	const content = await fs.readFile(filePath, 'utf8')
	auditUnicodeEscapes(filePath, content)
	auditStaticTranslationKeys(filePath, content)
	if (filePath.endsWith('.vue')) auditTemplate(filePath, content)
}

if (issues.length) {
	console.error(`i18n audit failed with ${issues.length} issue(s):`)
	for (const issue of issues) console.error(`- ${issue}`)
	process.exitCode = 1
} else {
	const keyCount = flatten(en).size
	console.log(`i18n audit passed: ${keyCount} message values across ${localeNames.length} locales, 3 legal documents, ${sourceFiles.length} source files.`)
}

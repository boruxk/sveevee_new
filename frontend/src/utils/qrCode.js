const BLOCKS = {
	1: { data: [19], ecc: 7 },
	2: { data: [34], ecc: 10 },
	3: { data: [55], ecc: 15 },
	4: { data: [80], ecc: 20 },
	5: { data: [108], ecc: 26 },
	6: { data: [68, 68], ecc: 18 },
	7: { data: [78, 78], ecc: 20 },
	8: { data: [97, 97], ecc: 24 }
}

const ALIGNMENT_PATTERNS = {
	1: [],
	2: [6, 18],
	3: [6, 22],
	4: [6, 26],
	5: [6, 30],
	6: [6, 34],
	7: [6, 22, 38],
	8: [6, 24, 42]
}

const EXP = Array(512).fill(0)
const LOG = Array(256).fill(0)

let value = 1
for (let index = 0; index < 255; index += 1) {
	EXP[index] = value
	LOG[value] = index
	value <<= 1
	if (value & 0x100) {
		value ^= 0x11d
	}
}

for (let index = 255; index < 512; index += 1) {
	EXP[index] = EXP[index - 255]
}

function gfMul(left, right) {
	if (left === 0 || right === 0) {
		return 0
	}

	return EXP[LOG[left] + LOG[right]]
}

function rsGenerator(degree) {
	let result = [1]

	for (let index = 0; index < degree; index += 1) {
		const next = Array(result.length + 1).fill(0)
		const root = EXP[index]

		result.forEach((coefficient, coefficientIndex) => {
			next[coefficientIndex] ^= coefficient
			next[coefficientIndex + 1] ^= gfMul(coefficient, root)
		})

		result = next
	}

	return result.slice(1)
}

function rsRemainder(data, degree) {
	const generator = rsGenerator(degree)
	const result = Array(degree).fill(0)

	data.forEach((byte) => {
		const factor = byte ^ result.shift()
		result.push(0)

		generator.forEach((coefficient, index) => {
			result[index] ^= gfMul(coefficient, factor)
		})
	})

	return result
}

function pushBits(bits, valueToAppend, length) {
	for (let index = length - 1; index >= 0; index -= 1) {
		bits.push(((valueToAppend >>> index) & 1) === 1)
	}
}

function dataCodewords(text, version) {
	const bytes = Array.from(new TextEncoder().encode(text))
	const blockInfo = BLOCKS[version]
	const capacity = blockInfo.data.reduce((total, count) => total + count, 0)
	const bits = []

	pushBits(bits, 0x4, 4)
	pushBits(bits, bytes.length, 8)
	bytes.forEach((byte) => pushBits(bits, byte, 8))

	const capacityBits = capacity * 8
	const terminator = Math.min(4, capacityBits - bits.length)
	pushBits(bits, 0, terminator)

	while (bits.length % 8 !== 0) {
		bits.push(false)
	}

	const codewords = []
	for (let index = 0; index < bits.length; index += 8) {
		let byte = 0
		for (let offset = 0; offset < 8; offset += 1) {
			byte = (byte << 1) | (bits[index + offset] ? 1 : 0)
		}
		codewords.push(byte)
	}

	for (let padIndex = 0; codewords.length < capacity; padIndex += 1) {
		codewords.push(padIndex % 2 === 0 ? 0xec : 0x11)
	}

	return codewords
}

function chooseVersion(text) {
	const length = new TextEncoder().encode(text).length

	for (const [version, blockInfo] of Object.entries(BLOCKS)) {
		const capacity = blockInfo.data.reduce((total, count) => total + count, 0)
		if (length <= capacity - 2) {
			return Number(version)
		}
	}

	return 6
}

function interleavedCodewords(text, version) {
	const blockInfo = BLOCKS[version]
	const data = dataCodewords(text, version)
	const dataBlocks = []
	let offset = 0

	blockInfo.data.forEach((count) => {
		dataBlocks.push(data.slice(offset, offset + count))
		offset += count
	})

	const eccBlocks = dataBlocks.map((block) => rsRemainder(block, blockInfo.ecc))
	const result = []

	for (let index = 0; index < Math.max(...blockInfo.data); index += 1) {
		dataBlocks.forEach((block) => {
			if (index < block.length) {
				result.push(block[index])
			}
		})
	}

	for (let index = 0; index < blockInfo.ecc; index += 1) {
		eccBlocks.forEach((block) => result.push(block[index]))
	}

	return result
}

function matrix(size) {
	return {
		modules: Array.from({ length: size }, () => Array(size).fill(false)),
		functions: Array.from({ length: size }, () => Array(size).fill(false))
	}
}

function setFunction(state, x, y, isDark) {
	if (x < 0 || y < 0 || y >= state.modules.length || x >= state.modules.length) {
		return
	}

	state.modules[y][x] = isDark
	state.functions[y][x] = true
}

function drawFinder(state, x, y) {
	for (let dy = -4; dy <= 4; dy += 1) {
		for (let dx = -4; dx <= 4; dx += 1) {
			const distance = Math.max(Math.abs(dx), Math.abs(dy))
			setFunction(state, x + dx, y + dy, distance !== 2 && distance !== 4)
		}
	}
}

function drawAlignment(state, x, y) {
	for (let dy = -2; dy <= 2; dy += 1) {
		for (let dx = -2; dx <= 2; dx += 1) {
			const distance = Math.max(Math.abs(dx), Math.abs(dy))
			setFunction(state, x + dx, y + dy, distance !== 1)
		}
	}
}

function reserveFormat(state) {
	const size = state.modules.length

	for (let index = 0; index < 9; index += 1) {
		if (index !== 6) {
			setFunction(state, 8, index, false)
			setFunction(state, index, 8, false)
		}
	}

	for (let index = 0; index < 8; index += 1) {
		setFunction(state, size - 1 - index, 8, false)
		setFunction(state, 8, size - 1 - index, false)
	}
}

function drawFunctionPatterns(state, version) {
	const size = state.modules.length

	drawFinder(state, 3, 3)
	drawFinder(state, size - 4, 3)
	drawFinder(state, 3, size - 4)

	for (let index = 8; index < size - 8; index += 1) {
		setFunction(state, 6, index, index % 2 === 0)
		setFunction(state, index, 6, index % 2 === 0)
	}

	const alignments = ALIGNMENT_PATTERNS[version]
	alignments.forEach((x) => {
		alignments.forEach((y) => {
			const nearFinder = (x === 6 && y === 6) || (x === 6 && y === size - 7) || (x === size - 7 && y === 6)
			if (!nearFinder) {
				drawAlignment(state, x, y)
			}
		})
	})

	reserveFormat(state)
	setFunction(state, 8, size - 8, true)

	if (version >= 7) {
		drawVersionBits(state, version)
	}
}

function drawVersionBits(state, version) {
	const size = state.modules.length
	let remainder = version

	for (let index = 0; index < 12; index += 1) {
		remainder = (remainder << 1) ^ (((remainder >>> 11) & 1) * 0x1f25)
	}

	const bits = (version << 12) | remainder

	for (let index = 0; index < 18; index += 1) {
		const isDark = ((bits >>> index) & 1) === 1
		const x = size - 11 + (index % 3)
		const y = Math.floor(index / 3)

		setFunction(state, x, y, isDark)
		setFunction(state, y, x, isDark)
	}
}

function formatBits() {
	const errorCorrectionLow = 1
	const mask = 0
	const data = (errorCorrectionLow << 3) | mask
	let remainder = data

	for (let index = 0; index < 10; index += 1) {
		remainder = (remainder << 1) ^ (((remainder >>> 9) & 1) * 0x537)
	}

	return ((data << 10) | remainder) ^ 0x5412
}

function drawFormatBits(state) {
	const size = state.modules.length
	const bits = formatBits()
	const bit = (index) => ((bits >>> index) & 1) === 1

	for (let index = 0; index <= 5; index += 1) {
		setFunction(state, 8, index, bit(index))
	}
	setFunction(state, 8, 7, bit(6))
	setFunction(state, 8, 8, bit(7))
	setFunction(state, 7, 8, bit(8))
	for (let index = 9; index < 15; index += 1) {
		setFunction(state, 14 - index, 8, bit(index))
	}

	for (let index = 0; index < 8; index += 1) {
		setFunction(state, size - 1 - index, 8, bit(index))
	}
	for (let index = 8; index < 15; index += 1) {
		setFunction(state, 8, size - 15 + index, bit(index))
	}
	setFunction(state, 8, size - 8, true)
}

function placeCodewords(state, codewords) {
	const size = state.modules.length
	const bits = []

	codewords.forEach((byte) => pushBits(bits, byte, 8))

	let bitIndex = 0
	let upward = true

	for (let right = size - 1; right >= 1; right -= 2) {
		if (right === 6) {
			right -= 1
		}

		for (let vertical = 0; vertical < size; vertical += 1) {
			const y = upward ? size - 1 - vertical : vertical

			for (let offset = 0; offset < 2; offset += 1) {
				const x = right - offset
				if (!state.functions[y][x]) {
					const mask = (x + y) % 2 === 0
					state.modules[y][x] = Boolean(bits[bitIndex]) !== mask
					bitIndex += 1
				}
			}
		}

		upward = !upward
	}
}

function qrModules(text) {
	const version = chooseVersion(text)
	const size = version * 4 + 17
	const state = matrix(size)

	drawFunctionPatterns(state, version)
	placeCodewords(state, interleavedCodewords(text, version))
	drawFormatBits(state)

	return state.modules
}

export function qrSvg(text, options = {}) {
	const border = options.border ?? 4
	const dark = options.dark || '#151f2d'
	const light = options.light || '#ffffff'
	const modules = qrModules(text)
	const size = modules.length + border * 2
	const cells = []

	modules.forEach((row, y) => {
		row.forEach((isDark, x) => {
			if (isDark) {
				cells.push(`<rect x="${x + border}" y="${y + border}" width="1" height="1"/>`)
			}
		})
	})

	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges"><rect width="${size}" height="${size}" fill="${light}"/><g fill="${dark}">${cells.join('')}</g></svg>`
}

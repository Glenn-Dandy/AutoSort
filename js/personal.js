/* global OC, OCP, t */
(function() {
	const root = document.getElementById('autosort')
	const tr = (text, vars) => t('autosort', text, vars)
	const url = (path) => OC.generateUrl('/apps/autosort' + path)

	async function api(method, path, body) {
		const response = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error(data.message ? tr(data.message) : tr('The request failed'))
		}
		return data
	}

	const success = (text) => OCP.Toast.success(text)
	const failure = (error) => OCP.Toast.error(error.message)

	function el(tag, props = {}, ...children) {
		const node = document.createElement(tag)
		Object.entries(props).forEach(([key, value]) => {
			if (key === 'class') {
				node.className = value
			} else if (key.startsWith('on')) {
				node.addEventListener(key.slice(2), value)
			} else {
				node[key] = value
			}
		})
		children.forEach((child) => child !== null && node.append(child))
		return node
	}

	function field(label, control, hint) {
		return el('label', { class: 'autosort-field' }, el('span', { textContent: label }), control, hint ? el('small', { textContent: hint }) : null)
	}

	function pick(title, mime, onPicked) {
		const directory = mime === 'httpd/unix-directory'
		OC.dialogs.filepicker(title, (path) => onPicked(path.replace(/^\/+/, '')), false, mime, true, directory ? OC.dialogs.FILEPICKER_TYPE_CHOOSE : undefined)
	}

	function folderField(label, value, hint) {
		const input = el('input', { type: 'text', value })
		const button = el('button', {
			type: 'button',
			textContent: tr('Choose'),
			onclick: (event) => {
				event.preventDefault()
				pick(label, 'httpd/unix-directory', (path) => { input.value = path })
			},
		})
		return { input, node: field(label, el('div', { class: 'autosort-row' }, input, button), hint) }
	}

	function settingsSection(state) {
		const source = folderField(tr('Inbox folder'), state.source, tr('New PDFs in exactly this folder are sorted. Subfolders are left alone.'))
		const unmatched = folderField(tr('Folder for unsorted PDFs (optional)'), state.unmatched, tr('PDFs that fit no rule are moved here. Leave blank to keep them in the inbox.'))
		const tagInput = (value) => {
			const input = el('input', { type: 'text', value })
			input.setAttribute('list', 'autosort-tags')
			return input
		}
		const triggerTag = tagInput(state.triggerTag)
		const afterTag = tagInput(state.afterTag)
		const removeTrigger = el('input', { type: 'checkbox', checked: state.removeTrigger })
		const save = el('button', {
			type: 'button',
			textContent: tr('Save'),
			onclick: () => api('PUT', '/settings', {
				source: source.input.value,
				unmatched: unmatched.input.value,
				triggerTag: triggerTag.value,
				afterTag: afterTag.value,
				removeTrigger: removeTrigger.checked,
			})
				.then((saved) => {
					source.input.value = saved.source
					unmatched.input.value = saved.unmatched
					state.triggerTag = saved.triggerTag
					state.afterTag = saved.afterTag
					state.removeTrigger = saved.removeTrigger
					success(tr('Saved'))
				})
				.catch(failure),
		})
		return [el('h2', { textContent: 'AutoSort' }),
			el('p', { class: 'autosort-hint', textContent: tr('Moves and renames PDFs from your inbox folder based on the text inside them. PDFs without text wait in the inbox until OCR has processed them.') }),
			state.pdftotext ? null : el('div', { class: 'autosort-warning', textContent: tr('pdftotext is not installed on this server, so PDFs cannot be read. Please ask your administrator to install poppler-utils.') }),
			source.node, unmatched.node,
			el('datalist', { id: 'autosort-tags' }, ...state.tags.map((tag) => el('option', { value: tag }))),
			el('h2', { textContent: tr('Working with tags (optional)') }),
			field(tr('Only sort once this tag is set'), triggerTag, tr('For example the tag Workflow OCR sets after the recognition. Leave blank to sort right away.')),
			el('label', { class: 'autosort-field' }, removeTrigger, ' ' + tr('Remove this tag after sorting')),
			field(tr('Set this tag after sorting'), afterTag, tr('Optional. Tags have to exist in Nextcloud already.')),
			save]
	}

	const today = new Date()
	const pad = (number) => String(number).padStart(2, '0')
	const dateParts = { JJJJ: String(today.getFullYear()), JJ: String(today.getFullYear()).slice(2), MM: pad(today.getMonth() + 1), TT: pad(today.getDate()) }
	const fillBlock = (block) => block.replace(/JJJJ|JJ|MM|TT/g, (part) => dateParts[part])

	function previewName(template) {
		if (template.trim() === '') {
			return tr('The name stays the same')
		}
		const name = template
			.replace(/\{((?:JJJJ|JJ|MM|TT)(?:[._ -]?(?:JJJJ|JJ|MM|TT))*)\}/g, (match, block) => fillBlock(block))
			.replace('{Datum}', fillBlock('JJJJ.MM.TT')).replace('{Jahr}', dateParts.JJJJ).replace('{Monat}', dateParts.MM).replace('{Tag}', dateParts.TT)
			.replace('{Original}', tr('original name'))
			.replace('{Treffer}', tr('found term'))
		return tr('Example: {name}', { name: /\.pdf$/i.test(name) ? name : name + '.pdf' })
	}

	function filenameField(value) {
		const input = el('input', { type: 'text', value })
		const preview = el('small', { textContent: previewName(value) })
		input.addEventListener('input', () => { preview.textContent = previewName(input.value) })

		const blocks = ['{JJJJ.MM.TT}', '{JJJJ-MM-TT}', '{TT.MM.JJJJ}', '{JJJJMMTT}']
		const select = el('select', { class: 'autosort-preset' },
			el('option', { value: '', textContent: tr('Insert …') }),
			...blocks.map((block) => el('option', { value: block, textContent: tr('Date as {example}', { example: fillBlock(block.slice(1, -1)) }) })),
			el('option', { value: '{JJJJ}', textContent: tr('Year') }),
			el('option', { value: '{MM}', textContent: tr('Month') }),
			el('option', { value: '{Original}', textContent: tr('Original name') }),
			el('option', { value: '{Treffer}', textContent: tr('First found OR term') }),
		)
		select.addEventListener('change', () => {
			const start = input.selectionStart ?? input.value.length
			const end = input.selectionEnd ?? input.value.length
			input.value = input.value.slice(0, start) + select.value + input.value.slice(end)
			select.value = ''
			preview.textContent = previewName(input.value)
			input.focus()
		})

		return { input, node: el('div', { class: 'autosort-field' }, el('span', { textContent: tr('New file name') }), input, select, preview) }
	}

	const compareNames = (first, second) => first.localeCompare(second, undefined, { numeric: true, sensitivity: 'base' })
	const subKey = (category, subcategory) => category + '\u0000' + subcategory

	function groupRules(state) {
		const categories = new Map()
		state.rules.forEach((rule) => {
			const category = rule.category || ''
			const subcategory = rule.subcategory || ''
			categories.has(category) || categories.set(category, new Map())
			const subs = categories.get(category)
			subs.has(subcategory) || subs.set(subcategory, [])
			subs.get(subcategory).push(rule)
		})

		const build = (name) => {
			const subs = categories.get(name)
			const direct = subs.get('') || []
			const subgroups = [...subs.keys()].filter(Boolean).sort(compareNames).map((sub) => ({ name: sub, rules: subs.get(sub) }))
			return { name, direct, subgroups, rules: [...direct, ...subgroups.flatMap((group) => group.rules)] }
		}

		// Rules without a category come last so that the display matches the order of evaluation
		const result = [...categories.keys()].filter(Boolean).sort(compareNames).map(build)
		return categories.has('') ? [...result, build('')] : result
	}

	function saveOrder(state) {
		state.rules = groupRules(state).flatMap((group) => group.rules)
		return api('POST', '/order', { ids: state.rules.filter((rule) => rule.id).map((rule) => rule.id) })
	}

	function ruleCard(state, rule, render) {
		const terms = el('textarea', { value: rule.terms.join('\n') })
		const termsAny = el('textarea', { value: (rule.termsAny || []).join('\n') })
		const target = folderField(tr('Target folder'), rule.target)
		const filename = filenameField(rule.filename)
		const dateAfter = el('input', { type: 'text', value: rule.dateAfter })
		const category = el('input', { type: 'text', value: rule.category || '' })
		category.setAttribute('list', 'autosort-categories')
		const subcategory = el('input', { type: 'text', value: rule.subcategory || '' })
		subcategory.setAttribute('list', 'autosort-subcategories')
		const enabled = el('input', { type: 'checkbox', checked: rule.enabled })
		const card = el('div', { class: 'autosort-rule' + (rule.enabled ? '' : ' disabled') })
		card.dataset.search = [...rule.terms, ...(rule.termsAny || []), rule.target, rule.filename, rule.dateAfter, rule.category, rule.subcategory].join(' ').toLowerCase()

		const values = () => ({
			terms: terms.value.split('\n'),
			termsAny: termsAny.value.split('\n'),
			target: target.input.value,
			filename: filename.input.value,
			dateAfter: dateAfter.value,
			category: category.value,
			subcategory: subcategory.value,
			enabled: enabled.checked,
		})

		const save = () => (rule.id
			? api('PUT', '/rules/' + rule.id, values())
			: api('POST', '/rules', values()))
			.then((saved) => {
				Object.assign(rule, saved)
				state.open.add(rule.category || '')
				state.open.add(subKey(rule.category || '', rule.subcategory || ''))
				return saveOrder(state)
			})
			.then(() => {
				success(tr('Saved'))
				render()
			})
			.catch(failure)

		// Rules only move within their category
		const shift = (offset) => {
			const siblings = state.rules.filter((item) => (item.category || '') === (rule.category || '') && (item.subcategory || '') === (rule.subcategory || ''))
			const other = siblings[siblings.indexOf(rule) + offset]
			if (!other) {
				return
			}
			const first = state.rules.indexOf(rule)
			const second = state.rules.indexOf(other)
			state.rules[first] = other
			state.rules[second] = rule
			saveOrder(state).then(render).catch(failure)
		}

		let confirming = false
		const remove = el('button', {
			type: 'button',
			textContent: tr('Delete'),
			onclick: () => {
				if (!confirming) {
					confirming = true
					remove.textContent = tr('Really delete?')
					return
				}
				const done = () => {
					state.rules.splice(state.rules.indexOf(rule), 1)
					render()
				}
				if (rule.id) {
					api('DELETE', '/rules/' + rule.id).then(done).catch(failure)
				} else {
					done()
				}
			},
		})

		card.append(
			el('div', { class: 'autosort-grid' },
				field(tr('Terms (AND)'), terms, tr('All of them have to appear. One per line, case and extra spaces do not matter.')),
				field(tr('Terms (OR)'), termsAny, tr('At least one of them has to appear. One per line.')),
				target.node,
				filename.node,
				field(tr('The date comes after (optional)'), dateAfter, tr('For example Invoice date. Otherwise the first date in the document is used.')),
			),
			el('div', { class: 'autosort-actions' },
				el('label', {}, enabled, ' ' + tr('Active')),
				el('label', { class: 'autosort-inline' }, tr('Category') + ' ', category),
				el('label', { class: 'autosort-inline' }, tr('Subcategory') + ' ', subcategory),
				el('span', { class: 'autosort-spacer' }),
				el('button', { type: 'button', textContent: '↑', title: tr('Move up'), onclick: () => shift(-1) }),
				el('button', { type: 'button', textContent: '↓', title: tr('Move down'), onclick: () => shift(1) }),
				remove,
				el('button', { type: 'button', class: 'primary', textContent: tr('Save'), onclick: save }),
			),
		)
		return card
	}

	function addRule(state, category, subcategory, render) {
		state.rules.push({ terms: [], termsAny: [], target: '', filename: '', dateAfter: '', category, subcategory, enabled: true })
		state.open.add(category)
		state.open.add(subKey(category, subcategory))
		render()
	}

	function nameControl(label, value, onSubmit) {
		const holder = el('span', { class: 'autosort-inline' })
		holder.append(el('button', {
			type: 'button',
			textContent: label,
			onclick: () => {
				const input = el('input', { type: 'text', value })
				const apply = () => input.value.trim() !== '' && onSubmit(input.value.trim())
				input.addEventListener('keydown', (event) => event.key === 'Enter' && apply())
				holder.replaceChildren(input, el('button', { type: 'button', textContent: tr('OK'), onclick: apply }))
				input.focus()
			},
		}))
		return holder
	}

	function foldable(state, key, cssClass, title, count, children) {
		const section = el('details', { class: cssClass, open: state.open.has(key) },
			el('summary', {}, el('strong', { textContent: title }), el('span', { class: 'autosort-count', textContent: ' (' + count + ')' })),
			...children,
		)
		section.addEventListener('toggle', () => {
			// While searching everything is opened, which should not be remembered
			if (state.search.trim() !== '') {
				return
			}
			if (section.open) {
				state.open.add(key)
			} else {
				state.open.delete(key)
			}
		})
		return section
	}

	function subcategorySection(state, category, group, render) {
		const rename = (name) => {
			const done = () => {
				group.rules.forEach((rule) => { rule.subcategory = name })
				state.open.add(subKey(category, name))
				saveOrder(state).then(render).catch(failure)
			}
			if (group.rules.some((rule) => rule.id)) {
				api('POST', '/subcategory', { category, old: group.name, new: name }).then(done).catch(failure)
			} else {
				done()
			}
		}
		return foldable(state, subKey(category, group.name), 'autosort-subcategory', group.name, group.rules.length, [
			...group.rules.map((rule) => ruleCard(state, rule, render)),
			el('div', { class: 'autosort-actions' },
				el('button', { type: 'button', textContent: tr('Add rule'), onclick: () => addRule(state, category, group.name, render) }),
				nameControl(tr('Rename subcategory'), group.name, rename),
			),
		])
	}

	function categorySection(state, group, render) {
		const rename = (name) => {
			const done = () => {
				group.rules.forEach((rule) => { rule.category = name })
				state.open.delete(group.name)
				state.open.add(name)
				saveOrder(state).then(render).catch(failure)
			}
			if (group.rules.some((rule) => rule.id)) {
				api('POST', '/category', { old: group.name, new: name }).then(done).catch(failure)
			} else {
				done()
			}
		}
		return foldable(state, group.name, 'autosort-category', group.name || tr('Without category'), group.rules.length, [
			...group.direct.map((rule) => ruleCard(state, rule, render)),
			...group.subgroups.map((subgroup) => subcategorySection(state, group.name, subgroup, render)),
			el('div', { class: 'autosort-actions' },
				el('button', { type: 'button', textContent: tr('Add rule'), onclick: () => addRule(state, group.name, '', render) }),
				group.name ? nameControl(tr('Add subcategory'), '', (name) => addRule(state, group.name, name, render)) : null,
				group.name ? nameControl(tr('Rename category'), group.name, rename) : null,
			),
		])
	}

	function addCategory(state, render) {
		const input = el('input', { type: 'text', placeholder: tr('New category, for example Car') })
		const create = () => {
			if (input.value.trim() !== '') {
				addRule(state, input.value.trim(), '', render)
			}
		}
		input.addEventListener('keydown', (event) => event.key === 'Enter' && create())
		return el('div', { class: 'autosort-row autosort-new-category' }, input, el('button', { type: 'button', textContent: tr('Add category'), onclick: create }))
	}

	// Hides instead of rebuilding, so unsaved changes in a rule survive a search
	function applySearch(box, hint, query) {
		const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean)
		const searching = words.length > 0
		let matches = 0
		box.querySelectorAll('.autosort-rule').forEach((card) => {
			card.hidden = searching && !words.every((word) => card.dataset.search.includes(word))
			matches += card.hidden ? 0 : 1
		})
		box.querySelectorAll('details').forEach((section) => {
			const visible = !searching || section.querySelector('.autosort-rule:not([hidden])') !== null
			section.hidden = !visible
			if (searching && visible) {
				section.open = true
			}
		})
		hint.textContent = searching ? tr('Found: {count}', { count: matches }) : ''
	}

	function statusText(result) {
		switch (result.status) {
		case 'no_text': return tr('This PDF has no text yet. It stays in the inbox until OCR has processed it.')
		case 'match': return tr('Would be moved to:')
		case 'unmatched': return tr('No rule fits. Would be moved to:')
		default: return tr('No rule fits. It stays in the inbox.')
		}
	}

	function testResult(result, state) {
		const box = el('div', { class: 'autosort-result' })
		if (result.waitingForTag) {
			box.append(el('p', { class: 'autosort-missing', textContent: tr('This PDF does not have the tag {tag} yet, so it is not sorted for now.', { tag: result.waitingForTag }) }))
		}
		box.append(el('strong', { textContent: statusText(result) }))
		if (result.destination) {
			box.append(el('div', { textContent: result.destination }))
		}
		result.rules.forEach((item) => {
			const rule = state.rules.find((candidate) => candidate.id === item.id)
			const siblings = state.rules.filter((candidate) => (candidate.category || '') === (rule?.category || ''))
			const prefix = rule?.category ? rule.category + ', ' : ''
			const list = el('ul')
			const addTerms = (terms, label) => terms.forEach((term) => list.append(el('li', {
				class: term.found ? 'autosort-found' : 'autosort-missing',
				textContent: (term.found ? '✓ ' : '✗ ') + label + term.term,
			})))
			addTerms(item.terms, '')
			addTerms(item.termsAny || [], tr('or') + ': ')
			const date = item.date
				? tr('Date found: {date}', { date: item.date })
				: tr('No date found')
			box.append(el('p', { textContent: prefix + tr('Rule {number}', { number: siblings.indexOf(rule) + 1 }) + ', ' + date }), list)
		})
		return box
	}

	function testSection(state) {
		const input = el('input', { type: 'text', placeholder: tr('Path to a PDF in your files') })
		const output = el('div')
		const browse = el('button', {
			type: 'button',
			textContent: tr('Choose'),
			onclick: () => pick(tr('Choose a PDF'), 'application/pdf', (path) => { input.value = path }),
		})
		const run = el('button', {
			type: 'button',
			class: 'primary',
			textContent: tr('Test'),
			onclick: () => api('POST', '/test', { path: input.value })
				.then((result) => output.replaceChildren(testResult(result, state)))
				.catch(failure),
		})
		return [el('h2', { textContent: tr('Try it out') }),
			el('p', { class: 'autosort-hint', textContent: tr('Shows what would happen to a PDF. Nothing is moved.') }),
			el('div', { class: 'autosort-row' }, input, browse, run), output]
	}

	const logLabels = () => ({
		match: tr('Moved'),
		unmatched: tr('Moved to unsorted'),
		none: tr('No rule fits'),
		no_text: tr('No text yet'),
		no_tag: tr('Waiting for tag'),
		error: tr('Error'),
	})

	function logTarget(entry) {
		if (entry.message || !entry.destination || !entry.fileId) {
			return entry.message || entry.destination || ''
		}
		return el('a', {
			class: 'autosort-link',
			href: OC.generateUrl('/f/{id}', { id: entry.fileId }),
			target: '_blank',
			rel: 'noopener',
			title: tr('Open PDF'),
			textContent: entry.destination,
		})
	}

	function logTable(entries) {
		if (entries.length === 0) {
			return el('p', { class: 'autosort-hint', textContent: tr('Nothing has been processed yet.') })
		}
		const labels = logLabels()
		const body = el('tbody')
		entries.forEach((entry) => body.append(el('tr', { class: 'autosort-log-' + entry.status },
			el('td', { textContent: new Date(entry.created * 1000).toLocaleString() }),
			el('td', { textContent: entry.fileName }),
			el('td', { textContent: labels[entry.status] ?? entry.status }),
			el('td', {}, logTarget(entry)),
		)))
		return el('table', { class: 'autosort-log' },
			el('thead', {}, el('tr', {},
				el('th', { textContent: tr('Time') }),
				el('th', { textContent: tr('File') }),
				el('th', { textContent: tr('Result') }),
				el('th', { textContent: tr('Target or error') }),
			)),
			body)
	}

	function logSection() {
		const output = el('div')
		const refresh = () => api('GET', '/log').then((entries) => output.replaceChildren(logTable(entries))).catch(failure)
		refresh()
		return {
			refresh,
			nodes: [el('h2', { textContent: tr('Log') }),
				el('p', { class: 'autosort-hint', textContent: tr('Shows what AutoSort did with your PDFs, newest first.') }),
				el('div', { class: 'autosort-actions' },
					el('button', { type: 'button', textContent: tr('Refresh'), onclick: refresh }),
					el('button', {
						type: 'button',
						textContent: tr('Clear log'),
						onclick: () => api('DELETE', '/log').then(refresh).catch(failure),
					}),
				),
				output],
		}
	}

	function sortSection(onDone) {
		const button = el('button', {
			type: 'button',
			class: 'primary',
			textContent: tr('Sort now'),
			onclick: () => {
				button.disabled = true
				api('POST', '/sort')
					.then((results) => {
						const moved = results.filter((result) => result.destination).length
						success(tr('{count} PDFs checked, {moved} moved', { count: results.length, moved }))
					})
					.catch(failure)
					.finally(() => {
						button.disabled = false
						onDone()
					})
			},
		})
		return [el('h2', { textContent: tr('Sort now') }),
			el('p', { class: 'autosort-hint', textContent: tr('Processes every PDF in the inbox folder right away, without waiting for the background job.') }),
			button]
	}

	function render(state) {
		const rerender = () => render(state)
		const log = logSection()
		const groups = groupRules(state)
		const rulesBox = el('div', {}, ...groups.map((group) => categorySection(state, group, rerender)))
		const searchHint = el('span', { class: 'autosort-hint' })
		const search = el('input', { type: 'search', value: state.search, placeholder: tr('Search rules') })
		search.addEventListener('input', () => {
			const wasSearching = state.search.trim() !== ''
			state.search = search.value
			if (wasSearching && state.search.trim() === '') {
				// Bring back which sections were open before the search
				rerender()
				return
			}
			applySearch(rulesBox, searchHint, state.search)
		})
		applySearch(rulesBox, searchHint, state.search)
		root.replaceChildren(
			...settingsSection(state).filter(Boolean),
			el('h2', { textContent: tr('Rules') }),
			el('p', { class: 'autosort-hint', textContent: tr('Rules are checked from top to bottom. The first one that fits is used. Open a category to see its rules.') }),
			el('datalist', { id: 'autosort-categories' }, ...groups.filter((group) => group.name).map((group) => el('option', { value: group.name }))),
			el('datalist', { id: 'autosort-subcategories' }, ...[...new Set(groups.flatMap((group) => group.subgroups.map((subgroup) => subgroup.name)))].sort(compareNames).map((name) => el('option', { value: name }))),
			el('div', { class: 'autosort-row autosort-search' }, search, searchHint),
			rulesBox,
			addCategory(state, rerender),
			...sortSection(log.refresh),
			...testSection(state),
			...log.nodes,
		)
	}

	api('GET', '/state').then((state) => {
		state.open = new Set([''])
		state.search = ''
		const stored = state.rules.map((rule) => rule.id).join()
		state.rules = groupRules(state).flatMap((group) => group.rules)
		// The server checks rules in this order, so it has to match what is shown
		if (state.rules.map((rule) => rule.id).join() !== stored) {
			saveOrder(state).catch(failure)
		}
		render(state)
	}).catch(failure)
})()

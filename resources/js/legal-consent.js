/**
 * The Alpine component behind the wording dialog.
 *
 * ## Why this file exists at all, when the package ships no other JavaScript
 *
 * Alpine's CSP build parses the expressions in your attributes with its own grammar instead of
 * handing them to `eval`. That grammar accepts assignments, ternaries, object and array literals,
 * calls, member and index access and the usual operators — and it rejects arrow functions, optional
 * chaining, template literals, nullish coalescing, spread, `new`, and more than one statement in an
 * attribute.
 *
 * The dialog shipped in 0.36.0 with a `fetch(...).then(...).catch(...)` chain written inline. Every
 * one of those callbacks is an arrow function, the guard used optional chaining, and the whole thing
 * was several statements. Under a Content-Security-Policy without `unsafe-eval` it therefore never
 * ran: the dialog opened onto nothing while `aria-haspopup` had already promised a screen reader
 * that it would work. That is worse than not offering it, which is why it is fixed rather than
 * documented.
 *
 * A file like this one is ordinary JavaScript and none of those restrictions apply to it. An
 * attribute reduced to a call — `load($event)` — parses under BOTH builds, so one template serves a
 * consumer with a strict policy and one without.
 *
 * ## It registers itself, so no build step is needed
 *
 * WireKit ships a prebuilt bundle precisely so that a consumer needs no bundler, and a package that
 * required one to use an optional feature would take that away. This file is published into
 * `public/` and loaded with a plain script tag, which is also what a strict policy expects: a served
 * file under `script-src 'self'`, never an inline script.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('legalConsentDialog', (name, url) => ({
        name,
        url,
        body: '',
        state: 'idle',

        /**
         * Fetch the published text the first time this dialog is opened, and keep it afterwards.
         *
         * The window event is shared by every modal on the page, so the name is what says whether
         * this one is meant. `state === 'idle'` is what makes it happen once: a reader who opens,
         * closes and opens again gets the text that is already here rather than a second request.
         *
         * A failure is reported rather than swallowed. A dialog that opens onto an empty box reads
         * as a document with no content — to somebody who is then asked to tick that they read it —
         * so the failed state says so and points back at the link, which never stopped working.
         */
        load(event) {
            if (event.detail.name !== this.name || this.state !== 'idle') {
                return
            }

            this.state = 'loading'

            fetch(this.url, { headers: { Accept: 'text/html' } })
                .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
                .then((html) => {
                    this.body = html
                    this.state = 'ready'
                })
                .catch(() => {
                    this.state = 'failed'
                })
        },
    }))
})

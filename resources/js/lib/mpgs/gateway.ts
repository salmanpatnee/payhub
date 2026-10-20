/**
 * The ONLY file that talks to the Mastercard Gateway browser libraries:
 * session.js (hosted card fields) and three-ds.min.js (the 3DS JavaScript API).
 *
 * Written from the bank's test gateway documentation (Hosted Session "Making a
 * Payment" and "3DS with Hosted Session"), NOT yet proven against a sandbox
 * (spec 0005, AC-13). Every spot the sandbox spike must confirm is marked
 * SPIKE. Keep all gateway specifics in this file so a correction lands here.
 *
 * Card data never passes through this app: the hosted fields keep it inside
 * gateway frames, and the form only ever handles the session id.
 *
 * Both libraries must be configured ONCE per page load. A retry after a decline
 * therefore reloads the page (see notice.ts) instead of re-mounting on a new
 * session.
 */

/** What the server returned from POST /pay/{uuid}/mpgs/session. */
export type MpgsSession = {
    session_id: string
    order_id: string
    auth_transaction_id: string
    attempt_id: number
}

/** CSS selectors of the readonly inputs the hosted fields replace. */
export type MpgsFieldTargets = {
    cardNumber: string
    expiryMonth: string
    expiryYear: string
    securityCode: string
    cardholderName: string
}

export type MpgsFieldCallbacks = {
    /** A hosted field's validity changed. `field` is a key of MpgsFieldTargets. */
    onFieldInvalid: (field: keyof MpgsFieldTargets, invalid: boolean) => void
}

export type MpgsGatewayConfig = {
    gatewayHost: string
    merchantId: string
    apiVersion: number
}

export type MpgsThreeDsResult = {
    /** true when the gateway recommends proceeding to PAY (frictionless or challenge passed). */
    authenticated: boolean
}

/** DOM id (no #) of the container the 3DS library injects its frame into. */
export const THREE_DS_CONTAINER_ID = 'mpgs-3ds-challenge'

const SCRIPT_TIMEOUT_MS = 15_000
const THREE_DS_TIMEOUT_MS = 5 * 60 * 1000

let config: MpgsGatewayConfig | null = null
let pendingSessionUpdate: { resolve: () => void, reject: (e: Error) => void } | null = null
let threeDsAbort: ((e: Error) => void) | null = null

function loadScript(src: string, attrs: Record<string, string> = {}): Promise<void> {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = src
        Object.entries(attrs).forEach(([key, value]) => script.setAttribute(key, value))

        const timer = window.setTimeout(() => reject(new Error(`Timed out loading ${src}`)), SCRIPT_TIMEOUT_MS)
        script.onload = () => { window.clearTimeout(timer); resolve() }
        script.onerror = () => { window.clearTimeout(timer); reject(new Error(`Could not load ${src}`)) }
        document.head.appendChild(script)
    })
}

/**
 * Load session.js and the 3DS library from the account's gateway host.
 *
 * The session.js version in the URL must equal the API version used to create
 * the session (the server uses the account's api_version for both).
 *
 * SPIKE (Q1): the 3DS script path `/static/threeDS/1.3.0/three-ds.min.js` is
 * inferred from a relative path in the documentation sample.
 */
export async function loadLibraries(gatewayConfig: MpgsGatewayConfig): Promise<void> {
    config = gatewayConfig
    const host = gatewayConfig.gatewayHost.replace(/\/+$/, '')
    const w = window as any

    // The 3DS script reads its error and cancel handlers from these attributes.
    w.mpgsThreeDsError = (e: any) => { threeDsAbort?.(new Error(e?.msg ?? 'The bank verification failed.')) }
    w.mpgsThreeDsCancel = () => { threeDsAbort?.(new Error('The bank verification was cancelled.')) }

    await loadScript(`${host}/form/version/${gatewayConfig.apiVersion}/merchant/${encodeURIComponent(gatewayConfig.merchantId)}/session.js`)
    await loadScript(`${host}/static/threeDS/1.3.0/three-ds.min.js`, {
        'data-error': 'mpgsThreeDsError',
        'data-cancel': 'mpgsThreeDsCancel',
    })

    if (!w.PaymentSession || !w.ThreeDS) {
        throw new Error('The payment libraries did not load.')
    }
}

function fieldKeyForSelector(targets: MpgsFieldTargets, selector: string): keyof MpgsFieldTargets | null {
    const entry = (Object.entries(targets) as [keyof MpgsFieldTargets, string][]).find(([, value]) => value === selector)

    return entry ? entry[0] : null
}

/**
 * Attach the hosted fields to the server issued session and configure the 3DS
 * library on it. Call exactly once per page load.
 *
 * `frameEmbeddingMitigation` is x-frame-options: PayHub already sends
 * `X-Frame-Options: DENY` on every page except the 3DS return page.
 */
export async function mountFields(
    session: MpgsSession,
    targets: MpgsFieldTargets,
    callbacks: MpgsFieldCallbacks,
): Promise<void> {
    const w = window as any

    if (!config) {
        throw new Error('Gateway libraries are not loaded.')
    }

    await new Promise<void>((resolve, reject) => {
        w.PaymentSession.configure({
            session: session.session_id,
            fields: {
                card: {
                    number: targets.cardNumber,
                    securityCode: targets.securityCode,
                    expiryMonth: targets.expiryMonth,
                    expiryYear: targets.expiryYear,
                    nameOnCard: targets.cardholderName,
                },
            },
            frameEmbeddingMitigation: ['x-frame-options'],
            callbacks: {
                initialized: (response: any) => {
                    if (response?.status === 'ok') {
                        resolve()
                    } else {
                        reject(new Error(`Hosted fields failed to initialize: ${response?.status ?? 'unknown'}`))
                    }
                },
                formSessionUpdate: (response: any) => {
                    const pending = pendingSessionUpdate
                    pendingSessionUpdate = null

                    if (!pending) {
                        return
                    }

                    if (response?.status === 'ok') {
                        pending.resolve()

                        return
                    }

                    pending.reject(new Error(
                        response?.status === 'fields_in_error'
                            ? 'Please check your card details and try again.'
                            : 'Your card details could not be read. Please try again.',
                    ))
                },
            },
            interaction: {
                displayControl: { formatCard: 'EMBOSSED', invalidFieldCharacters: 'REJECT' },
            },
        })
    })

    w.PaymentSession.onValidityChange(Object.values(targets).map((selector) => selector), (selector: string, result: any) => {
        const field = fieldKeyForSelector(targets, selector)

        if (field) {
            // Incomplete is not an error while the payer is still typing.
            callbacks.onFieldInvalid(field, !result?.isValid && !result?.isIncomplete)
        }
    })

    w.ThreeDS.configure({
        merchantId: config.merchantId,
        sessionId: session.session_id,
        containerId: THREE_DS_CONTAINER_ID,
        callback: () => undefined,
        configuration: { wsVersion: config.apiVersion },
    })
}

/** Push the typed card details from the hosted fields into the session. */
export function captureCard(): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        pendingSessionUpdate = { resolve, reject }
        ;(window as any).PaymentSession.updateSessionFromForm('card')
    })
}

/**
 * Show the bank's challenge page. The library does NOT render it unless
 * `fullScreenRedirect` is set; it hands us the HTML in `htmlRedirectCode` (a form
 * that posts into a `challengeFrame` iframe, plus an inline script that submits it).
 * Inline script is blocked by the pay page CSP and not run by innerHTML anyway, so
 * we insert the markup and submit the form ourselves. The bank then posts back to
 * the 3DS return page inside that frame, which messages this page.
 */
function renderChallenge(html: string): void {
    const container = document.getElementById(THREE_DS_CONTAINER_ID)

    if (!container) {
        throw new Error('The bank verification area is missing from the page.')
    }

    container.innerHTML = html

    // Prefer the known ids, but fall back to any form / iframe in the markup: the exact
    // ids are not documented, so don't depend on them.
    const frame = container.querySelector<HTMLElement>('#challengeFrame, #redirectTo3ds1Frame, iframe')

    if (frame) {
        frame.style.width = '100%'
        frame.style.minHeight = '420px'
        frame.style.border = '0'
    }

    const form = container.querySelector<HTMLFormElement>(
        '#threedsChallengeRedirectForm, #threedsFrictionLessRedirectForm, #redirectTo3ds1Form, form',
    )

    if (!form) {
        // SPIKE: structure only (tags and ids), never the markup itself: it holds the bank's tokens.
        const structure = Array.from(container.querySelectorAll('*')).map((el) => el.tagName.toLowerCase() + (el.id ? `#${el.id}` : ''))
        console.warn('[MPGS] 3DS challenge HTML has no form. Elements:', structure.join(' '))

        throw new Error('The bank verification could not be started.')
    }

    // The form may post to a named frame that is missing from the markup; give it one.
    if (!frame && form.target && !document.getElementsByName(form.target).length) {
        const created = document.createElement('iframe')
        created.name = form.target
        created.style.width = '100%'
        created.style.minHeight = '420px'
        created.style.border = '0'
        container.appendChild(created)
    }

    form.submit()
}

/**
 * Run 3DS: initiate authentication, then authenticate the payer, using the ids
 * the server issued. Any bank challenge renders inside THREE_DS_CONTAINER_ID.
 *
 * Authenticated means the gateway recommended PROCEED. The browser result is
 * only a hint: PAY on the server is what decides the payment.
 *
 * Proven against the Bank Alfalah test gateway: for a challenge the authenticatePayer
 * callback fires right away with `htmlRedirectCode` (3ds2 status C, authentication
 * still pending), so we render it (renderChallenge) and wait for the return page.
 * The challenge HTML is a <form> + iframe#challengeFrame; the container must sit
 * outside the payment <form> or the nested form tag is dropped.
 *
 * LOCAL TESTING: the bank's ACS (a public page) posts back to the callback URL, and
 * Chrome blocks a public page from reaching a local address (payhub.test ->
 * 127.0.0.1). Test the full 3DS return through a public https URL.
 *
 * SPIKE (Q4): (a) whether name and email are enough or the bank wants a billing
 * address (pass it in `optionalParams.billing`); (d) whether `fullScreenRedirect`
 * is needed for some banks; the exact shape of the challenge HTML (form id) and of
 * the data the bank posts back to the return page.
 */
export function authenticatePayer(session: MpgsSession): Promise<MpgsThreeDsResult> {
    const ThreeDS = (window as any).ThreeDS

    return new Promise<MpgsThreeDsResult>((resolve, reject) => {
        const timer = window.setTimeout(
            () => reject(new Error('The bank verification took too long.')),
            THREE_DS_TIMEOUT_MS,
        )
        // The 3DS return page (framed on this page) posts this when the bank challenge ends.
        const onReturn = (event: MessageEvent) => {
            const data = event.data

            if (event.origin !== window.location.origin || data?.type !== 'mpgs-3ds-return') {
                return
            }

            if (data.orderId && data.orderId !== session.order_id) {
                return
            }

            // An empty recommendation is accepted: PAY on the server decides the real outcome.
            const proceed = !data.recommendation || data.recommendation === 'PROCEED'

            if (!proceed) {
                console.warn('[MPGS] 3DS challenge ended without PROCEED:', data.recommendation, data.result)
            }

            finish(() => resolve({ authenticated: proceed }))
        }
        const finish = (fn: () => void) => {
            window.clearTimeout(timer)
            window.removeEventListener('message', onReturn)
            threeDsAbort = null
            fn()
        }
        threeDsAbort = (e) => finish(() => reject(e))

        ThreeDS.initiateAuthentication(session.order_id, session.auth_transaction_id, (initiated: any) => {
            if (initiated?.error || initiated?.gatewayRecommendation !== 'PROCEED') {
                // SPIKE: kept so the sandbox run shows why 3DS stopped (no card data in this response).
                console.warn('[MPGS] 3DS initiate did not proceed:', JSON.stringify(initiated))
                finish(() => resolve({ authenticated: false }))

                return
            }

            ThreeDS.authenticatePayer(session.order_id, session.auth_transaction_id, (authenticated: any) => {
                if (authenticated?.error || authenticated?.gatewayRecommendation !== 'PROCEED') {
                    console.warn('[MPGS] 3DS authenticate did not proceed:', JSON.stringify(authenticated))
                    finish(() => resolve({ authenticated: false }))

                    return
                }

                // No redirect HTML: the bank authenticated without asking the payer (frictionless).
                if (!authenticated.htmlRedirectCode) {
                    finish(() => resolve({ authenticated: true }))

                    return
                }

                // Bank challenge: PROCEED here only means "show the challenge". Paying now would hit the
                // gateway while authentication is still pending (a hard error), so show the challenge and
                // wait for the return page to report that the bank is done.
                window.addEventListener('message', onReturn)

                try {
                    renderChallenge(authenticated.htmlRedirectCode)
                } catch (e) {
                    finish(() => reject(e instanceof Error ? e : new Error('The bank verification failed.')))
                }
            }, {
                device: {
                    browserDetails: {
                        screenHeight: window.screen.height,
                        screenWidth: window.screen.width,
                    },
                },
            })
        }, { sourceOfFunds: { type: 'CARD' } })
    })
}

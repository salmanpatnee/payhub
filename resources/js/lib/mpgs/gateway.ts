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
 * Run 3DS: initiate authentication, then authenticate the payer, using the ids
 * the server issued. Any bank challenge renders inside THREE_DS_CONTAINER_ID.
 *
 * Authenticated means the gateway recommended PROCEED. The browser result is
 * only a hint: PAY on the server is what decides the payment.
 *
 * SPIKE (Q4): (a) whether name and email are enough or the bank wants a billing
 * address (pass it in `optionalParams.billing`); (b) whether the library renders
 * the challenge itself or `data.htmlRedirectCode` must be inserted into the
 * container; (c) the callback timing after a challenge; (d) whether
 * `fullScreenRedirect` is needed for some banks.
 */
export function authenticatePayer(session: MpgsSession): Promise<MpgsThreeDsResult> {
    const ThreeDS = (window as any).ThreeDS

    return new Promise<MpgsThreeDsResult>((resolve, reject) => {
        const timer = window.setTimeout(
            () => reject(new Error('The bank verification took too long.')),
            THREE_DS_TIMEOUT_MS,
        )
        const finish = (fn: () => void) => { window.clearTimeout(timer); threeDsAbort = null; fn() }
        threeDsAbort = (e) => finish(() => reject(e))

        ThreeDS.initiateAuthentication(session.order_id, session.auth_transaction_id, (initiated: any) => {
            if (initiated?.error || initiated?.gatewayRecommendation !== 'PROCEED') {
                finish(() => resolve({ authenticated: false }))

                return
            }

            ThreeDS.authenticatePayer(session.order_id, session.auth_transaction_id, (authenticated: any) => {
                finish(() => resolve({
                    authenticated: !authenticated?.error && authenticated?.gatewayRecommendation === 'PROCEED',
                }))
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

/**
 * The ONLY file that talks to the Mastercard Gateway browser libraries
 * (session.js hosted fields and the 3DS JavaScript library).
 *
 * STUB (spec 0005, AC-13). Every function below is the seam the sandbox spike
 * fills in. MpgsPaymentForm.vue and PayMpgs.vue depend on these signatures
 * only, so a correction from the spike lands here and in nowhere else. Until
 * then each function rejects with MpgsNotWiredError and the form shows its
 * "payment system unavailable" state.
 *
 * Open questions this file answers once the spike runs (spec `## Feature design`):
 *   1  session.js URL pattern, API version range, 3DS library URL and version
 *   4  3DS sequence (initiate, authenticate), callbacks, inline challenge
 *      rendering, whether name + email alone are enough for the bank
 *   10 exact script/frame hosts for the CSP (SecurityHeaders.php)
 *
 * Card data never passes through this app: the hosted fields keep it inside
 * gateway frames, and the form only ever handles the session id.
 */

export class MpgsNotWiredError extends Error {
    constructor(step: string) {
        super(`MPGS gateway step "${step}" is not wired yet (waiting on the sandbox spike).`)
        this.name = 'MpgsNotWiredError'
    }
}

/** What the server returned from POST /pay/{uuid}/mpgs/session. */
export type MpgsSession = {
    session_id: string
    order_id: string
    auth_transaction_id: string
    attempt_id: number
}

/** The DOM nodes the hosted fields mount into. */
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
    /** true when the bank approved the authentication (frictionless or challenge). */
    authenticated: boolean
}

/** Load session.js and the 3DS library from the configured gateway host. */
export async function loadLibraries(_config: MpgsGatewayConfig): Promise<void> {
    throw new MpgsNotWiredError('loadLibraries')
}

/** Attach the hosted fields to a server issued session. Re-callable for a retry session. */
export async function mountFields(
    _session: MpgsSession,
    _targets: MpgsFieldTargets,
    _callbacks: MpgsFieldCallbacks,
): Promise<void> {
    throw new MpgsNotWiredError('mountFields')
}

/** Push the typed card details from the hosted fields into the session. */
export async function captureCard(): Promise<void> {
    throw new MpgsNotWiredError('captureCard')
}

/**
 * Run 3DS (initiate authentication, then authenticate payer) with the ids the
 * server issued, rendering any bank challenge inside `challengeContainer`.
 */
export async function authenticatePayer(
    _session: MpgsSession,
    _challengeContainer: string,
): Promise<MpgsThreeDsResult> {
    throw new MpgsNotWiredError('authenticatePayer')
}

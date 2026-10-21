// Accept.js loader and tokenizer (spec 0007, AC-5). Loaded only on the pay page of an
// Authorize.net payment, from the URL the server passed in (config host, never the request).

type AcceptResponse = {
    messages: { resultCode: string }
    opaqueData?: { dataDescriptor: string; dataValue: string }
}

type AcceptGlobal = {
    dispatchData: (secureData: unknown, callback: (response: AcceptResponse) => void) => void
}

declare global {
    interface Window {
        Accept?: AcceptGlobal
    }
}

export type OpaqueData = { dataDescriptor: string; dataValue: string }

let loading: Promise<void> | null = null

export function loadAcceptJs(scriptUrl: string): Promise<void> {
    if (window.Accept) {
        return Promise.resolve()
    }

    loading ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script')
        script.src = scriptUrl
        script.async = true
        script.onload = () => (window.Accept ? resolve() : reject(new Error('Accept.js did not initialise')))
        script.onerror = () => {
            loading = null
            reject(new Error('Accept.js failed to load'))
        }
        document.head.appendChild(script)
    })

    return loading
}

/**
 * Swap the card for a one time code. Resolves null on any tokenize error so the caller
 * can show the fixed "check your card details" message; the gateway's error text and
 * the card values are never surfaced or logged.
 */
export function tokenizeCard(
    auth: { apiLoginId: string; clientKey: string },
    card: { number: string; month: string; year: string; cvv: string; zip: string },
): Promise<OpaqueData | null> {
    return new Promise((resolve) => {
        if (!window.Accept) {
            resolve(null)

            return
        }

        window.Accept.dispatchData(
            {
                authData: { clientKey: auth.clientKey, apiLoginID: auth.apiLoginId },
                cardData: {
                    cardNumber: card.number,
                    month: card.month,
                    year: card.year,
                    cardCode: card.cvv,
                    zip: card.zip,
                },
            },
            (response) => {
                if (response.messages.resultCode === 'Ok' && response.opaqueData) {
                    resolve({
                        dataDescriptor: response.opaqueData.dataDescriptor,
                        dataValue: response.opaqueData.dataValue,
                    })
                } else {
                    resolve(null)
                }
            },
        )
    })
}

// Card form helpers for the Authorize.net pay page (spec 0007, AC-4).
// Pure functions only: card values must never be logged, stored or sent anywhere
// except to Accept.js, so nothing here touches the console, storage or the network.

/** Keep digits only. */
export function digitsOnly(value: string): string {
    return value.replace(/\D/g, '')
}

/** Luhn check on a digits-only string. */
export function passesLuhn(digits: string): boolean {
    let sum = 0
    let double = false

    for (let i = digits.length - 1; i >= 0; i--) {
        let n = digits.charCodeAt(i) - 48

        if (double) {
            n *= 2

            if (n > 9) {
                n -= 9
            }
        }

        sum += n
        double = !double
    }

    return digits.length > 0 && sum % 10 === 0
}

/** 13 to 19 digits and a valid Luhn checksum. */
export function isCardNumberValid(value: string): boolean {
    const digits = digitsOnly(value)

    return digits.length >= 13 && digits.length <= 19 && passesLuhn(digits)
}

/** Groups of four for display, e.g. "4111 1111 1111 1111". */
export function formatCardNumber(value: string): string {
    return digitsOnly(value).slice(0, 19).replace(/(.{4})/g, '$1 ').trim()
}

/** "1225" or "12/25" becomes "12/25" while typing. */
export function formatExpiry(value: string): string {
    const digits = digitsOnly(value).slice(0, 4)

    return digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits
}

/** Splits "MM/YY" into two digit strings, or null when the shape is wrong. */
export function parseExpiry(value: string): { month: string; year: string } | null {
    const match = /^(\d{2})\/(\d{2})$/.exec(value.trim())

    return match ? { month: match[1], year: match[2] } : null
}

/** A real month that is not in the past (the current month still counts). */
export function isExpiryValid(value: string, now: Date = new Date()): boolean {
    const parsed = parseExpiry(value)

    if (!parsed) {
        return false
    }

    const month = Number(parsed.month)
    const year = 2000 + Number(parsed.year)

    if (month < 1 || month > 12) {
        return false
    }

    const currentYear = now.getFullYear()
    const currentMonth = now.getMonth() + 1

    return year > currentYear || (year === currentYear && month >= currentMonth)
}

/** 3 or 4 digits. */
export function isCvvValid(value: string): boolean {
    return /^\d{3,4}$/.test(value)
}

/** 3 to 10 letters, digits, spaces or hyphens (matches the server rule). */
export function isZipValid(value: string): boolean {
    return /^[A-Za-z0-9 -]{3,10}$/.test(value)
}

export type CardFieldErrors = {
    number?: string
    expiry?: string
    cvv?: string
    zip?: string
}

/** Browser side checks that run before anything is sent to Accept.js. */
export function validateCard(
    card: { number: string; expiry: string; cvv: string; zip: string },
    now: Date = new Date(),
): CardFieldErrors {
    const errors: CardFieldErrors = {}

    if (!isCardNumberValid(card.number)) {
        errors.number = 'Enter a valid card number.'
    }

    if (!isExpiryValid(card.expiry, now)) {
        errors.expiry = 'Enter a valid expiry date (MM/YY).'
    }

    if (!isCvvValid(card.cvv)) {
        errors.cvv = 'Enter the 3 or 4 digit security code.'
    }

    if (!isZipValid(card.zip)) {
        errors.zip = 'Enter a valid ZIP or postal code.'
    }

    return errors
}

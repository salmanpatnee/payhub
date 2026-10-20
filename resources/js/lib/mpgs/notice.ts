/**
 * The gateway libraries can only be configured once per page load, so after a
 * decline the pay page reloads to get a fresh session. This carries the
 * "your card was declined" message across that reload. Storage can be blocked
 * (private windows), so every access is wrapped and the page works without it.
 */
const key = (uuid: string): string => `mpgs-notice:${uuid}`

export function reloadWithNotice(uuid: string, message: string): void {
    try {
        window.sessionStorage.setItem(key(uuid), message)
    } catch {
        // Storage unavailable: the page still reloads, just without the message.
    }

    window.location.reload()
}

export function takeNotice(uuid: string): string | null {
    try {
        const message = window.sessionStorage.getItem(key(uuid))
        window.sessionStorage.removeItem(key(uuid))

        return message
    } catch {
        return null
    }
}

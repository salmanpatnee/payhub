import { http } from '@inertiajs/vue3'

declare global {
    interface Window {
        // Used by the build (vite.config.ts renderBuiltUrl) to find script chunks at run time.
        __assetUrl?: (file: string) => string
    }
}

let cachedBase: string | null = null

// The folder PayHub is served from ("/payhub"), or "" when it sits at the top of a domain.
// The root view writes it into a meta tag from the request, so one build works in both places.
export function basePath(): string {
    if (typeof document === 'undefined') {
        return ''
    }

    cachedBase ??= (document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '').replace(/\/+$/, '')

    return cachedBase
}

if (typeof window !== 'undefined') {
    window.__assetUrl = (file) => `${basePath()}/build/${file}`
}

function hasBase(path: string, base: string): boolean {
    return path === base || path.startsWith(`${base}/`) || path.startsWith(`${base}?`) || path.startsWith(`${base}#`)
}

/** Adds the folder to an app path ("/pay/1" -> "/payhub/pay/1"). Safe to call twice; leaves other hosts alone. */
export function withBase(url: string): string {
    const base = basePath()

    if (!base || url.startsWith('//')) {
        return url
    }

    if (url.startsWith('/')) {
        return hasBase(url, base) ? url : base + url
    }

    // Inertia hands over absolute URLs for its own requests: only touch this site's.
    if (/^https?:\/\//i.test(url)) {
        const parsed = new URL(url)

        if (parsed.origin === window.location.origin && !hasBase(parsed.pathname, base)) {
            parsed.pathname = base + parsed.pathname

            return parsed.href
        }
    }

    return url
}

/** The reverse, for comparing the browser's path with app paths such as sidebar links. */
export function stripBase(path: string): string {
    const base = basePath()

    return base && hasBase(path, base) ? path.slice(base.length) || '/' : path
}

function prefixAnchors(root: ParentNode): void {
    root.querySelectorAll<HTMLAnchorElement>('a[href^="/"]:not([href^="//"])').forEach((anchor) => {
        const href = anchor.getAttribute('href') ?? ''
        const fixed = withBase(href)

        if (fixed !== href) {
            anchor.setAttribute('href', fixed)
        }
    })
}

/**
 * Makes an app that writes "/login"-style addresses work from a folder. Does nothing at the top
 * of a domain. Covers Inertia's own requests, plain fetch calls and link hrefs; code that sets
 * window.location by hand uses withBase() itself.
 */
export function installBasePath(): void {
    if (!basePath()) {
        return
    }

    http.onRequest((config) => {
        config.url = withBase(config.url)

        return config
    })

    const nativeFetch = window.fetch.bind(window)

    window.fetch = (input: RequestInfo | URL, init?: RequestInit) =>
        nativeFetch(typeof input === 'string' ? withBase(input) : input, init)

    // Inertia's Link renders the path it was given, so fix the attribute for open in new tab,
    // copy link and a full page reload. Clicks already go through the request handler above.
    const start = (): void => {
        prefixAnchors(document)

        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.type === 'attributes' && mutation.target instanceof HTMLAnchorElement) {
                    const href = mutation.target.getAttribute('href') ?? ''

                    if (withBase(href) !== href) {
                        mutation.target.setAttribute('href', withBase(href))
                    }
                } else {
                    mutation.addedNodes.forEach((node) => {
                        if (node instanceof HTMLElement) {
                            prefixAnchors(node)

                            if (node instanceof HTMLAnchorElement) {
                                const href = node.getAttribute('href') ?? ''

                                if (withBase(href) !== href) {
                                    node.setAttribute('href', withBase(href))
                                }
                            }
                        }
                    })
                }
            }
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] })
    }

    if (document.body) {
        start()
    } else {
        document.addEventListener('DOMContentLoaded', start, { once: true })
    }
}

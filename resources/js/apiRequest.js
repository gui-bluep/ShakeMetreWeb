/**
 * L'appel JSON que font les écrans de cette application, en un seul endroit.
 *
 * Les points `/api` de ce projet vivent dans le groupe `web` : ils sont appelés par un humain
 * connecté, donc avec la session et le jeton CSRF. Ce qui suit encode cette décision une fois -
 * les mêmes en-têtes, la même lecture d'erreur - plutôt que dans chaque page. Deux copies de
 * cette fonction finiraient par ne plus rapporter les erreurs de la même façon, et c'est le
 * genre d'écart qu'on ne remarque que le jour où un message n'apparaît pas.
 *
 * `X-Requested-With` n'est pas décoratif : c'est en partie ce qui fait rendre les échecs de
 * validation en JSON plutôt qu'en redirection.
 */
export function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}

/**
 * @param {string} url
 * @param {string} method
 * @param {?object} payload  absent plutôt que `null` dans le corps quand il n'y a rien à envoyer
 * @returns {Promise<object>} le corps décodé ; `{}` sur un 204
 * @throws {Error} avec le message du serveur - la première erreur de validation s'il y en a une
 */
export async function apiRequest(url, method, payload = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: payload === null ? undefined : JSON.stringify(payload),
    });

    if (response.status === 204) {
        return {};
    }

    const body = await response.json().catch(() => null);

    if (! response.ok) {
        throw new Error(
            body?.errors
                ? Object.values(body.errors).flat()[0]
                : (body?.message ?? `Échec (HTTP ${response.status})`)
        );
    }

    return body;
}

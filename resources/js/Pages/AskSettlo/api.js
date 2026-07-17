/**
 * Reads the Laravel XSRF-TOKEN cookie so plain fetch() calls (used for SSE and
 * JSON endpoints) satisfy CSRF verification without Axios.
 */
function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON fetch helper scoped to the Ask Settlo endpoints. Throws on non-2xx with
 * the parsed body attached so callers can read quota/limit messages.
 */
export async function api(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'Request failed');
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}

/**
 * POSTs a message to the streaming endpoint and invokes onDelta for each text
 * chunk and onDone with the final metadata. Rejects on transport/HTTP failure
 * so the caller can fall back to the non-streaming endpoint.
 */
export async function stream(url, content, { onDelta, onDone }) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'text/event-stream',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ content }),
    });

    if (!response.ok || !response.body) {
        throw new Error('Stream unavailable');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        let boundary = buffer.indexOf('\n\n');

        while (boundary !== -1) {
            const frame = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);
            boundary = buffer.indexOf('\n\n');

            let event = 'message';
            let dataLine = '';

            for (const line of frame.split('\n')) {
                if (line.startsWith('event:')) {
                    event = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    dataLine += line.slice(5).trim();
                }
            }

            if (!dataLine) {
                continue;
            }

            const payload = JSON.parse(dataLine);

            if (event === 'delta') {
                onDelta(payload.text ?? '');
            } else if (event === 'done') {
                onDone(payload);
            }
        }
    }
}

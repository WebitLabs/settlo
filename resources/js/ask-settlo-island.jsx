import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import Index from './Pages/AskSettlo/Index';

function Island({ bootstrapUrl }) {
    const [payload, setPayload] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        fetch(bootstrapUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Bootstrap failed (${response.status})`);
                }
                return response.json();
            })
            .then(setPayload)
            .catch((e) => setError(e.message));
    }, [bootstrapUrl]);

    if (error) {
        return (
            <div className="rounded-xl bg-danger-50 p-6 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                Could not load the chat. Refresh the page to try again.
            </div>
        );
    }

    if (!payload) {
        return (
            <div className="flex h-96 items-center justify-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <span className="inline-block size-4 animate-spin rounded-full border-2 border-gray-300 border-t-transparent dark:border-gray-600 dark:border-t-transparent"></span>
                Loading Ask Settlo…
            </div>
        );
    }

    return <Index {...payload} />;
}

const root = document.getElementById('ask-settlo-root');

if (root) {
    createRoot(root).render(<Island bootstrapUrl={root.dataset.bootstrap} />);
}

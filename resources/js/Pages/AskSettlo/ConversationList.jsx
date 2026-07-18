import { useMemo, useState } from 'react';

const GROUP_LABELS = {
    today: 'Today',
    week: 'This week',
    earlier: 'Earlier',
};

const GROUP_ORDER = ['today', 'week', 'earlier'];

function badgeDot(badge) {
    if (badge === 'pending') {
        return (
            <span className="relative flex h-2 w-2 shrink-0" title="Awaiting accountant">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-amber-500" />
            </span>
        );
    }

    if (badge === 'answered') {
        return <span className="h-2 w-2 shrink-0 rounded-full bg-[#00A878]" title="Accountant answered" />;
    }

    return null;
}

/**
 * Left pane: searchable, date-grouped list of the owner's conversations plus a
 * "New conversation" action.
 */
export default function ConversationList({ conversations, activeId, onSelect, onNew }) {
    const [query, setQuery] = useState('');

    const grouped = useMemo(() => {
        const term = query.trim().toLowerCase();
        const filtered = conversations.filter(
            (c) =>
                term === '' ||
                (c.title ?? '').toLowerCase().includes(term) ||
                (c.preview ?? '').toLowerCase().includes(term),
        );

        return GROUP_ORDER.map((group) => ({
            group,
            items: filtered.filter((c) => c.group === group),
        })).filter((section) => section.items.length > 0);
    }, [conversations, query]);

    return (
        <aside className="flex w-72 shrink-0 flex-col overflow-hidden border-r border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div className="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3.5 dark:border-white/10">
                <span className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                    <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-[#00A878] text-[11px] font-semibold text-white">
                        S
                    </span>
                    Ask Settlo
                </span>
                <button
                    type="button"
                    onClick={onNew}
                    className="flex items-center gap-1 rounded-lg bg-[#00A878] px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#0F6E56]"
                >
                    <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    New
                </button>
            </div>

            <div className="border-b border-gray-200 p-3 dark:border-white/10">
                <div className="relative">
                    <svg
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500"
                    >
                        <path
                            fillRule="evenodd"
                            d="M9 3.5a5.5 5.5 0 1 0 3.437 9.813l3.375 3.375a.75.75 0 1 0 1.06-1.06l-3.375-3.376A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z"
                            clipRule="evenodd"
                        />
                    </svg>
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search conversations..."
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pl-8 pr-3 text-xs text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#00A878] focus:ring-2 focus:ring-[#00A878]/20 dark:border-white/10 dark:bg-white/5 dark:text-gray-100 dark:placeholder:text-gray-500"
                    />
                </div>
            </div>

            <div className="flex-1 overflow-y-auto px-2 py-3">
                {grouped.length === 0 && (
                    <p className="px-2 py-4 text-center text-xs text-gray-400 dark:text-gray-500">No conversations yet</p>
                )}

                {grouped.map((section) => (
                    <div key={section.group} className="mb-4">
                        <p className="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {GROUP_LABELS[section.group]}
                        </p>
                        {section.items.map((conversation) => (
                            <button
                                type="button"
                                key={conversation.id}
                                onClick={() => onSelect(conversation.id)}
                                className={`mb-0.5 flex w-full flex-col gap-0.5 rounded-lg px-2.5 py-2 text-left transition ${
                                    conversation.id === activeId
                                        ? 'bg-[#00A878]/10 ring-1 ring-inset ring-[#00A878]/20 dark:bg-[#00A878]/15'
                                        : 'hover:bg-gray-100 dark:hover:bg-white/5'
                                }`}
                            >
                                <div className="flex items-center gap-2">
                                    {badgeDot(conversation.badge)}
                                    <span
                                        className={`truncate text-[13px] font-medium ${
                                            conversation.id === activeId
                                                ? 'text-[#0F6E56] dark:text-[#34d3a6]'
                                                : 'text-gray-900 dark:text-gray-100'
                                        }`}
                                    >
                                        {conversation.title}
                                    </span>
                                </div>
                                <span className="truncate text-[11px] text-gray-400 dark:text-gray-500">
                                    {conversation.preview}
                                </span>
                            </button>
                        ))}
                    </div>
                ))}
            </div>
        </aside>
    );
}

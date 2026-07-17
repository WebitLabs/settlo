import { useMemo, useState } from 'react';

const GROUP_LABELS = {
    today: 'Today',
    week: 'This week',
    earlier: 'Earlier',
};

const GROUP_ORDER = ['today', 'week', 'earlier'];

function badgeDot(badge) {
    if (badge === 'pending') {
        return <span className="h-2 w-2 shrink-0 rounded-full bg-[#F59E0B]" title="Awaiting accountant" />;
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
        <aside className="flex w-56 shrink-0 flex-col overflow-hidden border-r border-[#E2E8F0] bg-white">
            <div className="flex items-center justify-between border-b border-[#E2E8F0] px-3 py-3">
                <span className="text-[13px] font-medium text-[#0D1F2D]">Ask Settlo</span>
                <button
                    type="button"
                    onClick={onNew}
                    className="flex items-center gap-1 rounded-md bg-[#00A878] px-2.5 py-1 text-[11px] font-medium text-white hover:bg-[#0F6E56]"
                >
                    + New
                </button>
            </div>

            <div className="border-b border-[#E2E8F0] p-2.5">
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search conversations..."
                    className="w-full rounded-md border border-[#E2E8F0] bg-[#F0F2F5] px-2.5 py-1.5 text-[12px] text-[#0D1F2D] outline-none placeholder:text-[#9CA3AF] focus:border-[#00A878]"
                />
            </div>

            <div className="flex-1 overflow-y-auto px-2 py-2">
                {grouped.length === 0 && (
                    <p className="px-2 py-4 text-center text-[11px] text-[#9CA3AF]">No conversations yet</p>
                )}

                {grouped.map((section) => (
                    <div key={section.group} className="mb-3">
                        <p className="px-2 pb-1 text-[10px] font-medium uppercase tracking-wide text-[#9CA3AF]">
                            {GROUP_LABELS[section.group]}
                        </p>
                        {section.items.map((conversation) => (
                            <button
                                type="button"
                                key={conversation.id}
                                onClick={() => onSelect(conversation.id)}
                                className={`mb-1 flex w-full flex-col gap-0.5 rounded-md px-2.5 py-2 text-left transition ${
                                    conversation.id === activeId
                                        ? 'bg-[#F0F2F5]'
                                        : 'hover:bg-[#F0F2F5]'
                                }`}
                            >
                                <div className="flex items-center gap-2">
                                    {badgeDot(conversation.badge)}
                                    <span className="truncate text-[12px] font-medium text-[#0D1F2D]">
                                        {conversation.title}
                                    </span>
                                </div>
                                <span className="truncate text-[11px] text-[#9CA3AF]">{conversation.preview}</span>
                            </button>
                        ))}
                    </div>
                ))}
            </div>
        </aside>
    );
}

/**
 * Inline escalation card rendered under an assistant message. Flips between the
 * pending (amber), answered (green) and resolved (gray) states as the
 * accountant flow progresses.
 */
export default function EscalationCard({ escalation, onResolve, resolving }) {
    if (escalation.status === 'pending' || escalation.status === 'in_progress') {
        return (
            <div className="overflow-hidden rounded-xl border border-amber-300 bg-white shadow-sm dark:border-amber-500/30 dark:bg-gray-800">
                <div className="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-500/20 dark:bg-amber-500/10">
                    <span className="relative flex h-2.5 w-2.5">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                        <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                    </span>
                    <span className="text-xs font-semibold text-amber-800 dark:text-amber-300">
                        Waiting for accountant…
                    </span>
                    <span className="ml-auto rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-semibold text-white">
                        Pending
                    </span>
                </div>
                <div className="px-3 py-3">
                    <p className="mb-1 text-[11px] text-gray-400 dark:text-gray-500">Sent to accountant</p>
                    <p className="text-[13px] text-gray-900 dark:text-gray-100">{escalation.question}</p>
                    <p className="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                        {escalation.accountantName} · responds within 24h
                    </p>
                </div>
            </div>
        );
    }

    if (escalation.status === 'closed') {
        return (
            <div className="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                <div className="flex items-center gap-1.5 text-[12px] text-gray-500 dark:text-gray-400">
                    <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5 text-gray-400 dark:text-gray-500">
                        <path
                            fillRule="evenodd"
                            d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                            clipRule="evenodd"
                        />
                    </svg>
                    <span className="font-semibold text-gray-700 dark:text-gray-300">Resolved</span>
                    <span className="text-[11px] text-gray-400 dark:text-gray-500">
                        — verified by {escalation.accountantName}
                    </span>
                </div>
                {escalation.answer && (
                    <p className="mt-2 text-[13px] leading-relaxed text-gray-600 dark:text-gray-400">
                        {escalation.answer}
                    </p>
                )}
            </div>
        );
    }

    // answered
    return (
        <div className="overflow-hidden rounded-xl border border-[#00A878]/40 bg-white shadow-sm dark:border-[#00A878]/30 dark:bg-gray-800">
            <div className="flex items-center gap-2 border-b border-[#00A878]/20 bg-[#00A878]/10 px-3 py-2">
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-[#00A878] text-white">
                    <svg viewBox="0 0 20 20" fill="currentColor" className="h-2.5 w-2.5">
                        <path
                            fillRule="evenodd"
                            d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                            clipRule="evenodd"
                        />
                    </svg>
                </span>
                <span className="text-xs font-semibold text-[#0F6E56] dark:text-[#34d3a6]">Accountant verified</span>
                <span className="ml-auto rounded-full bg-[#00A878] px-2 py-0.5 text-[10px] font-semibold text-white">
                    Verified
                </span>
            </div>
            <div className="px-3 py-3">
                <p className="mb-1.5 text-[11px] text-gray-400 dark:text-gray-500">{escalation.question}</p>
                <p className="mb-3 text-[13px] leading-relaxed text-gray-900 dark:text-gray-100">{escalation.answer}</p>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[#00A878]/15 text-[8px] font-semibold text-[#0F6E56] dark:text-[#34d3a6]">
                            MS
                        </span>
                        {escalation.accountantName}
                    </div>
                    <button
                        type="button"
                        onClick={() => onResolve(escalation)}
                        disabled={resolving}
                        className="flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] font-semibold text-gray-600 transition hover:border-[#00A878] hover:text-[#0F6E56] disabled:opacity-50 dark:border-white/10 dark:text-gray-300 dark:hover:border-[#00A878] dark:hover:text-[#34d3a6]"
                    >
                        {resolving ? 'Resolving…' : 'Mark as resolved'}
                    </button>
                </div>
            </div>
        </div>
    );
}

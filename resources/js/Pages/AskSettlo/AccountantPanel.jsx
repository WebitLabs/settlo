/**
 * Right pane: the assigned accountant card, the monthly human-answer quota
 * tracker, and a compact escalation history that scrolls the chat to a message.
 */
export default function AccountantPanel({ accountant, quota, escalations, onSelectEscalation }) {
    const percent = quota.total > 0 ? Math.min(100, Math.round((quota.used / quota.total) * 100)) : 0;
    const maxedOut = quota.canEscalate && quota.remaining === 0;
    const barColor = percent >= 100 ? 'bg-rose-500' : percent >= 75 ? 'bg-amber-500' : 'bg-[#00A878]';

    return (
        <aside className="flex w-80 shrink-0 flex-col overflow-hidden border-l border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div className="border-b border-gray-200 px-4 py-3.5 dark:border-white/10">
                <p className="text-sm font-semibold text-gray-900 dark:text-white">My accountant</p>
                <p className="text-[11px] text-gray-400 dark:text-gray-500">Human verification</p>
            </div>

            <div className="border-b border-gray-200 px-4 py-4 dark:border-white/10">
                <div className="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#1A3344] text-[13px] font-semibold text-[#7FB3CC]">
                        {accountant.initials}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-[13px] font-semibold text-gray-900 dark:text-white">
                            {accountant.name}
                        </p>
                        <p className="truncate text-[11px] text-gray-500 dark:text-gray-400">{accountant.role}</p>
                        <div className="mt-1 flex items-center gap-1.5 text-[11px] font-medium text-[#0F6E56] dark:text-[#34d3a6]">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#00A878] opacity-60" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-[#00A878]" />
                            </span>
                            {accountant.availability}
                        </div>
                    </div>
                </div>
            </div>

            <div className="border-b border-gray-200 px-4 py-4 dark:border-white/10">
                <div className="mb-2 flex items-baseline justify-between">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Human answers
                    </p>
                    <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400">
                        <span className="text-gray-900 dark:text-white">{quota.used}</span> / {quota.total}
                    </p>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div
                        className={`h-full rounded-full transition-all ${barColor}`}
                        style={{ width: `${percent}%` }}
                    />
                </div>
                <p className="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500">
                    {quota.planName} plan · resets monthly
                </p>
                {maxedOut && (
                    <a
                        href="/app"
                        className="mt-3 flex items-center justify-center gap-1 rounded-lg bg-[#00A878] px-3 py-1.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-[#0F6E56]"
                    >
                        Upgrade to Confidence
                        <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5">
                            <path
                                fillRule="evenodd"
                                d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z"
                                clipRule="evenodd"
                            />
                        </svg>
                    </a>
                )}
            </div>

            <div className="flex-1 overflow-y-auto px-4 py-4">
                <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Escalation history
                </p>
                {escalations.length === 0 && (
                    <div className="rounded-xl border border-dashed border-gray-200 px-3 py-6 text-center dark:border-white/10">
                        <p className="text-[11px] text-gray-400 dark:text-gray-500">No escalations yet</p>
                        <p className="mt-0.5 text-[10px] text-gray-400 dark:text-gray-500">
                            Send an answer to your accountant to verify it.
                        </p>
                    </div>
                )}
                {escalations.map((escalation) => {
                    const pending = escalation.status === 'pending' || escalation.status === 'in_progress';
                    const answered = escalation.status === 'answered';
                    return (
                        <button
                            type="button"
                            key={escalation.id}
                            onClick={() => onSelectEscalation(escalation)}
                            className={`mb-1.5 block w-full rounded-lg border px-3 py-2.5 text-left transition ${
                                pending
                                    ? 'border-amber-300 bg-amber-50 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:hover:bg-amber-500/20'
                                    : answered
                                      ? 'border-[#00A878]/40 bg-[#00A878]/10 hover:bg-[#00A878]/15 dark:border-[#00A878]/30'
                                      : 'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5'
                            }`}
                        >
                            <div className="flex items-start gap-2">
                                <span
                                    className={`mt-1 h-2 w-2 shrink-0 rounded-full ${
                                        pending ? 'bg-amber-500' : answered ? 'bg-[#00A878]' : 'bg-gray-300 dark:bg-gray-600'
                                    }`}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[11px] font-medium text-gray-900 dark:text-gray-100">
                                        {escalation.question}
                                    </p>
                                    <p
                                        className={`mt-0.5 text-[10px] font-medium ${
                                            pending
                                                ? 'text-amber-700 dark:text-amber-400'
                                                : answered
                                                  ? 'text-[#0F6E56] dark:text-[#34d3a6]'
                                                  : 'text-gray-400 dark:text-gray-500'
                                        }`}
                                    >
                                        {pending ? 'Pending' : answered ? 'Answered' : 'Resolved'}
                                    </p>
                                </div>
                            </div>
                        </button>
                    );
                })}
            </div>
        </aside>
    );
}

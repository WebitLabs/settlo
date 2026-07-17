/**
 * Right pane: the assigned accountant card, the monthly human-answer quota
 * tracker, and a compact escalation history that scrolls the chat to a message.
 */
export default function AccountantPanel({ accountant, quota, escalations, onSelectEscalation }) {
    const percent = quota.total > 0 ? Math.min(100, Math.round((quota.used / quota.total) * 100)) : 0;
    const maxedOut = quota.canEscalate && quota.remaining === 0;

    return (
        <aside className="flex w-60 shrink-0 flex-col overflow-hidden border-l border-[#E2E8F0] bg-white">
            <div className="border-b border-[#E2E8F0] px-3.5 py-3">
                <p className="text-[12px] font-medium text-[#0D1F2D]">My accountant</p>
                <p className="text-[11px] text-[#9CA3AF]">Human verification</p>
            </div>

            <div className="border-b border-[#E2E8F0] px-3.5 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[#1A3344] text-[12px] font-medium text-[#7B9BAD]">
                        {accountant.initials}
                    </span>
                    <div className="min-w-0">
                        <p className="text-[13px] font-medium text-[#0D1F2D]">{accountant.name}</p>
                        <p className="truncate text-[11px] text-[#9CA3AF]">{accountant.role}</p>
                    </div>
                </div>
                <div className="mt-2 flex items-center gap-1.5 text-[11px] text-[#4A5568]">
                    <span className="h-2 w-2 rounded-full bg-[#00A878]" />
                    {accountant.availability}
                </div>
            </div>

            <div className="border-b border-[#E2E8F0] px-3.5 py-3">
                <p className="mb-1.5 text-[11px] font-medium text-[#4A5568]">Human answers this month</p>
                <div className="mb-1 h-1.5 overflow-hidden rounded-full bg-[#F0F2F5]">
                    <div className="h-full rounded-full bg-[#00A878]" style={{ width: `${percent}%` }} />
                </div>
                <p className="text-[10px] text-[#9CA3AF]">
                    {quota.used} of {quota.total} used ({quota.planName} plan)
                </p>
                {maxedOut && (
                    <a
                        href="/app"
                        className="mt-2 inline-block text-[11px] font-medium text-[#00A878] hover:underline"
                    >
                        Upgrade to Confidence →
                    </a>
                )}
            </div>

            <div className="flex-1 overflow-y-auto px-3.5 py-3">
                <p className="mb-2 text-[11px] font-medium text-[#4A5568]">Escalation history</p>
                {escalations.length === 0 && (
                    <p className="text-[11px] text-[#9CA3AF]">No escalations yet</p>
                )}
                {escalations.map((escalation) => {
                    const pending = escalation.status === 'pending' || escalation.status === 'in_progress';
                    return (
                        <button
                            type="button"
                            key={escalation.id}
                            onClick={() => onSelectEscalation(escalation)}
                            className={`mb-1.5 block w-full rounded-md border px-2.5 py-2 text-left ${
                                pending
                                    ? 'border-[#F59E0B] bg-[#FEF3C7]'
                                    : escalation.status === 'answered'
                                      ? 'border-[#00A878] bg-[#D4F0E8]'
                                      : 'border-[#E2E8F0] hover:border-[#00A878]'
                            }`}
                        >
                            <p className="truncate text-[11px] font-medium text-[#0D1F2D]">{escalation.question}</p>
                            <p className="text-[10px] text-[#9CA3AF]">
                                {pending ? 'Pending' : escalation.status === 'answered' ? 'Answered' : 'Resolved'}
                            </p>
                        </button>
                    );
                })}
            </div>
        </aside>
    );
}

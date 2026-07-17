/**
 * Inline escalation card rendered under an assistant message. Flips between the
 * pending (yellow), answered (green) and resolved (gray) states as the
 * accountant flow progresses.
 */
export default function EscalationCard({ escalation, onResolve, resolving }) {
    if (escalation.status === 'pending' || escalation.status === 'in_progress') {
        return (
            <div className="overflow-hidden rounded-xl border-[1.5px] border-[#F59E0B] bg-white">
                <div className="flex items-center gap-2 border-b border-[#FAC775] bg-[#FEF3C7] px-3 py-2">
                    <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[#F59E0B] border-t-transparent" />
                    <span className="text-xs font-medium text-[#854F0B]">Waiting for accountant…</span>
                    <span className="ml-auto rounded bg-[#F59E0B] px-2 py-0.5 text-[10px] font-medium text-white">Pending</span>
                </div>
                <div className="px-3 py-3">
                    <p className="mb-1.5 text-[11px] text-[#9CA3AF]">Sent to accountant</p>
                    <p className="text-[13px] text-[#0D1F2D]">{escalation.question}</p>
                    <p className="mt-2 text-[11px] text-[#4A5568]">{escalation.accountantName} · responds within 24h</p>
                </div>
            </div>
        );
    }

    if (escalation.status === 'closed') {
        return (
            <div className="rounded-xl border-[1.5px] border-[#E2E8F0] bg-[#F0F2F5] px-3 py-2.5">
                <div className="flex items-center gap-2 text-[12px] text-[#4A5568]">
                    <span className="font-medium">Resolved ✓</span>
                    <span className="text-[11px] text-[#9CA3AF]">— verified by {escalation.accountantName}</span>
                </div>
                {escalation.answer && (
                    <p className="mt-2 text-[13px] leading-relaxed text-[#4A5568]">{escalation.answer}</p>
                )}
            </div>
        );
    }

    // answered
    return (
        <div className="overflow-hidden rounded-xl border-[1.5px] border-[#00A878] bg-white">
            <div className="flex items-center gap-2 border-b border-[#B7E4D0] bg-[#F0FBF7] px-3 py-2">
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-[#00A878] text-[10px] text-white">✓</span>
                <span className="text-xs font-medium text-[#0F6E56]">Accountant verified</span>
                <span className="ml-auto rounded bg-[#00A878] px-2 py-0.5 text-[10px] font-medium text-white">Verified</span>
            </div>
            <div className="px-3 py-3">
                <p className="mb-1.5 text-[11px] text-[#9CA3AF]">{escalation.question}</p>
                <p className="mb-2.5 text-[13px] leading-relaxed text-[#0D1F2D]">{escalation.answer}</p>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2 text-[11px] text-[#4A5568]">
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[#D4F0E8] text-[8px] font-medium text-[#0F6E56]">MS</span>
                        {escalation.accountantName}
                    </div>
                    <button
                        type="button"
                        onClick={() => onResolve(escalation)}
                        disabled={resolving}
                        className="flex items-center gap-1 text-[11px] font-medium text-[#00A878] hover:underline disabled:opacity-50"
                    >
                        Mark as resolved
                    </button>
                </div>
            </div>
        </div>
    );
}

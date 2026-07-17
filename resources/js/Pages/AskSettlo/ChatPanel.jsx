import { useEffect, useRef, useState } from 'react';
import EscalationCard from './EscalationCard';

const CONFIDENCE_LABEL = 'Based on Swiss tax law · Verify for your specific situation';

function ContextPills({ context }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            <span className="rounded-full border border-[#E2E8F0] bg-[#F0F2F5] px-2.5 py-1 text-[11px] text-[#4A5568]">
                📍 {context.cantonCode}
            </span>
            <span className="rounded-full border border-[#E2E8F0] bg-[#F0F2F5] px-2.5 py-1 text-[11px] text-[#4A5568]">
                CHF {context.revenueYtd} YTD
            </span>
            <span className="rounded-full border border-[#E2E8F0] bg-[#F0F2F5] px-2.5 py-1 text-[11px] text-[#4A5568]">
                VAT: {context.vatStatus}
            </span>
        </div>
    );
}

function TypingIndicator() {
    return (
        <div className="flex items-center gap-1 px-3 py-2.5">
            {[0, 1, 2].map((i) => (
                <span
                    key={i}
                    className="h-1.5 w-1.5 animate-bounce rounded-full bg-[#00A878]"
                    style={{ animationDelay: `${i * 0.15}s` }}
                />
            ))}
        </div>
    );
}

function AssistantMessage({ message, quota, onEscalate, onResolve, escalatingId, resolvingId, registerRef }) {
    const isStreaming = message.streaming && !message.content;
    const canShowVerify = quota.canEscalate && message.role === 'assistant' && message.id && !message.escalation;
    const quotaExhausted = quota.remaining === 0;

    return (
        <div ref={(el) => registerRef(message.id, el)} className="flex items-start gap-2.5">
            <span className="mt-0.5 flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-lg bg-[#00A878] text-[11px] font-medium text-white">
                S
            </span>
            <div className="min-w-0 flex-1">
                <div className="rounded-[2px_12px_12px_12px] border border-[#E2E8F0] bg-white px-3.5 py-3 text-[13px] leading-relaxed text-[#0D1F2D]">
                    {isStreaming ? <TypingIndicator /> : <p className="whitespace-pre-wrap">{message.content}</p>}
                </div>

                {!isStreaming && message.id && (
                    <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span className="flex items-center gap-1 text-[10px] text-[#00A878]">✓ {CONFIDENCE_LABEL}</span>

                        {canShowVerify && (
                            <button
                                type="button"
                                title={quotaExhausted ? 'Monthly limit reached — upgrade to Confidence' : 'Ask a certified accountant to verify'}
                                disabled={quotaExhausted || escalatingId === message.id}
                                onClick={() => onEscalate(message)}
                                className="flex items-center gap-1 rounded-md border border-[#FAC775] bg-[#FEF3C7] px-2.5 py-1 text-[11px] font-medium text-[#854F0B] hover:bg-[#FEF0C0] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Verify with accountant
                            </button>
                        )}
                    </div>
                )}

                {message.escalation && (
                    <div className="mt-2">
                        <EscalationCard
                            escalation={message.escalation}
                            onResolve={onResolve}
                            resolving={resolvingId === message.escalation.id}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Center pane: context header, message thread, suggested-question chips on an
 * empty conversation, and the composer.
 */
export default function ChatPanel({
    context,
    conversationTitle,
    messages,
    streaming,
    quota,
    suggestedChips,
    onSend,
    onEscalate,
    onResolve,
    escalatingId,
    resolvingId,
    messageRefs,
}) {
    const [draft, setDraft] = useState('');
    const scrollRef = useRef(null);

    useEffect(() => {
        const node = scrollRef.current;
        if (node) {
            node.scrollTop = node.scrollHeight;
        }
    }, [messages]);

    const registerRef = (id, el) => {
        if (id && messageRefs) {
            messageRefs.current[id] = el;
        }
    };

    const submit = () => {
        const value = draft.trim();
        if (value === '' || streaming) {
            return;
        }
        setDraft('');
        onSend(value);
    };

    const isEmpty = messages.length === 0;

    return (
        <section className="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-[#E2E8F0] bg-white px-4 py-3">
                <div className="min-w-0">
                    <p className="truncate text-[13px] font-medium text-[#0D1F2D]">{conversationTitle}</p>
                    <p className="text-[11px] text-[#9CA3AF]">Settlo AI · Swiss tax assistant</p>
                </div>
                <ContextPills context={context} />
            </header>

            <div ref={scrollRef} className="flex flex-1 flex-col gap-3 overflow-y-auto bg-[#F0F2F5] p-4">
                {isEmpty && (
                    <div className="m-auto max-w-md text-center">
                        <div className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-[#00A878] text-[15px] font-medium text-white">
                            S
                        </div>
                        <p className="text-[14px] font-medium text-[#0D1F2D]">Ask Settlo anything about Swiss taxes</p>
                        <p className="mt-1 text-[12px] text-[#9CA3AF]">
                            VAT, AHV, deductions, Pillar 3a — answered with your business context.
                        </p>
                    </div>
                )}

                {messages.map((message) =>
                    message.role === 'user' ? (
                        <div key={message.id ?? message.tempId} className="flex justify-end">
                            <div className="max-w-[80%] rounded-[12px_12px_2px_12px] bg-[#1A3344] px-3.5 py-2.5 text-[13px] leading-relaxed text-white">
                                {message.content}
                            </div>
                        </div>
                    ) : (
                        <AssistantMessage
                            key={message.id ?? message.tempId}
                            message={message}
                            quota={quota}
                            onEscalate={onEscalate}
                            onResolve={onResolve}
                            escalatingId={escalatingId}
                            resolvingId={resolvingId}
                            registerRef={registerRef}
                        />
                    ),
                )}
            </div>

            {isEmpty && suggestedChips.length > 0 && (
                <div className="flex flex-wrap gap-1.5 border-t border-[#E2E8F0] bg-[#F0F2F5] px-4 py-2.5">
                    {suggestedChips.map((chip) => (
                        <button
                            type="button"
                            key={chip}
                            onClick={() => {
                                if (!streaming) {
                                    onSend(chip);
                                }
                            }}
                            className="rounded-full border border-[#CBD5E1] bg-white px-3 py-1 text-[11px] text-[#4A5568] hover:border-[#00A878] hover:text-[#00A878]"
                        >
                            {chip}
                        </button>
                    ))}
                </div>
            )}

            <div className="shrink-0 border-t border-[#E2E8F0] bg-white px-4 py-2.5">
                <div className="flex items-center gap-2 rounded-xl border border-[#CBD5E1] bg-[#F0F2F5] px-3 py-2">
                    <input
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                submit();
                            }
                        }}
                        placeholder="Ask about VAT, AHV, deductions..."
                        className="flex-1 bg-transparent text-[13px] text-[#0D1F2D] outline-none placeholder:text-[#9CA3AF]"
                    />
                    <button
                        type="button"
                        onClick={submit}
                        disabled={streaming || draft.trim() === ''}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#00A878] text-white disabled:cursor-not-allowed disabled:bg-[#CBD5E1]"
                        aria-label="Send"
                    >
                        ↑
                    </button>
                </div>
                <p className="mt-1.5 text-center text-[10px] text-[#9CA3AF]">
                    AI answers instantly · Accountant verification on Pro &amp; Confidence plans
                </p>
            </div>
        </section>
    );
}

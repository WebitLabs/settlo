import { useEffect, useRef, useState } from 'react';
import EscalationCard from './EscalationCard';

const CONFIDENCE_LABEL = 'Based on Swiss tax law · Verify for your specific situation';

function ContextPills({ context }) {
    const pill =
        'inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300';

    return (
        <div className="flex flex-wrap gap-1.5">
            <span className={pill}>📍 {context.cantonCode}</span>
            <span className={pill}>CHF {context.revenueYtd} YTD</span>
            <span className={pill}>VAT: {context.vatStatus}</span>
        </div>
    );
}

function TypingIndicator() {
    return (
        <div className="flex items-center gap-1 px-1 py-1">
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

function SettloAvatar() {
    return (
        <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#00A878] text-[11px] font-semibold text-white shadow-sm">
            S
        </span>
    );
}

function AssistantMessage({ message, quota, onEscalate, onResolve, escalatingId, resolvingId, registerRef }) {
    const isStreaming = message.streaming && !message.content;
    const canShowVerify = quota.canEscalate && message.role === 'assistant' && message.id && !message.escalation;
    const quotaExhausted = quota.remaining === 0;

    return (
        <div ref={(el) => registerRef(message.id, el)} className="flex items-start gap-2.5">
            <SettloAvatar />
            <div className="min-w-0 flex-1">
                <div className="w-fit max-w-[85%] rounded-2xl rounded-tl-sm border border-gray-200 bg-white px-4 py-3 text-[13px] leading-relaxed text-gray-900 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                    {isStreaming ? <TypingIndicator /> : <p className="whitespace-pre-wrap">{message.content}</p>}
                </div>

                {!isStreaming && message.id && (
                    <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span className="flex items-center gap-1 text-[10px] text-gray-400 dark:text-gray-500">
                            <svg viewBox="0 0 20 20" fill="currentColor" className="h-3 w-3 text-[#00A878]">
                                <path
                                    fillRule="evenodd"
                                    d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                                    clipRule="evenodd"
                                />
                            </svg>
                            {CONFIDENCE_LABEL}
                        </span>

                        {canShowVerify && (
                            <button
                                type="button"
                                title={
                                    quotaExhausted
                                        ? 'Monthly limit reached — upgrade to Confidence'
                                        : 'Ask a certified accountant to verify'
                                }
                                disabled={quotaExhausted || escalatingId === message.id}
                                onClick={() => onEscalate(message)}
                                className="flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5">
                                    <path
                                        fillRule="evenodd"
                                        d="M10 1c-1.716 0-3.408.106-5.07.31C3.806 1.45 3 2.414 3 3.517V16.75A2.25 2.25 0 0 0 5.25 19h9.5A2.25 2.25 0 0 0 17 16.75V3.517c0-1.103-.806-2.068-1.93-2.207A41.403 41.403 0 0 0 10 1Zm0 3a.75.75 0 0 1 .75.75v3.5h3.5a.75.75 0 0 1 0 1.5h-3.5v3.5a.75.75 0 0 1-1.5 0v-3.5h-3.5a.75.75 0 0 1 0-1.5h3.5v-3.5A.75.75 0 0 1 10 4Z"
                                        clipRule="evenodd"
                                    />
                                </svg>
                                {escalatingId === message.id ? 'Sending…' : 'Verify with accountant'}
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
        <section className="flex min-w-0 flex-1 flex-col overflow-hidden bg-gray-100 dark:bg-gray-950">
            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3 dark:border-white/10 dark:bg-gray-900">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-white">{conversationTitle}</p>
                    <p className="text-[11px] text-gray-400 dark:text-gray-500">Settlo AI · Swiss tax assistant</p>
                </div>
                <ContextPills context={context} />
            </header>

            <div ref={scrollRef} className="flex flex-1 flex-col gap-4 overflow-y-auto px-5 py-6">
                {isEmpty && (
                    <div className="m-auto w-full max-w-lg text-center">
                        <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-[#00A878] text-lg font-semibold text-white shadow-lg shadow-[#00A878]/25">
                            S
                        </div>
                        <p className="text-base font-semibold text-gray-900 dark:text-white">
                            Ask Settlo anything about Swiss taxes
                        </p>
                        <p className="mx-auto mt-1.5 max-w-sm text-[13px] text-gray-500 dark:text-gray-400">
                            VAT, AHV, deductions, Pillar 3a — answered with your business context.
                        </p>

                        {suggestedChips.length > 0 && (
                            <div className="mt-6 flex flex-wrap justify-center gap-2">
                                {suggestedChips.map((chip) => (
                                    <button
                                        type="button"
                                        key={chip}
                                        onClick={() => {
                                            if (!streaming) {
                                                onSend(chip);
                                            }
                                        }}
                                        className="rounded-full border border-gray-200 bg-white px-3.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm transition hover:border-[#00A878] hover:text-[#0F6E56] dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-[#00A878] dark:hover:text-[#34d3a6]"
                                    >
                                        {chip}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {messages.map((message) =>
                    message.role === 'user' ? (
                        <div key={message.id ?? message.tempId} className="flex justify-end">
                            <div className="max-w-[80%] rounded-2xl rounded-tr-sm bg-[#00A878] px-4 py-2.5 text-[13px] leading-relaxed text-white shadow-sm">
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

            <div className="shrink-0 border-t border-gray-200 bg-white px-5 py-3 dark:border-white/10 dark:bg-gray-900">
                <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 transition focus-within:border-[#00A878] focus-within:ring-2 focus-within:ring-[#00A878]/20 dark:border-white/10 dark:bg-white/5">
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
                        className="flex-1 bg-transparent text-[13px] text-gray-900 outline-none placeholder:text-gray-400 dark:text-gray-100 dark:placeholder:text-gray-500"
                    />
                    <button
                        type="button"
                        onClick={submit}
                        disabled={streaming || draft.trim() === ''}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#00A878] text-white shadow-sm transition hover:bg-[#0F6E56] disabled:cursor-not-allowed disabled:bg-gray-300 disabled:shadow-none dark:disabled:bg-white/10"
                        aria-label="Send"
                    >
                        <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4">
                            <path d="M10.75 15.25V6.66l2.97 2.97a.75.75 0 1 0 1.06-1.06l-4.25-4.25a.75.75 0 0 0-1.06 0L5.22 8.57a.75.75 0 0 0 1.06 1.06l2.97-2.97v8.59a.75.75 0 0 0 1.5 0Z" />
                        </svg>
                    </button>
                </div>
                <p className="mt-2 text-center text-[10px] text-gray-400 dark:text-gray-500">
                    AI answers instantly · Accountant verification on Pro &amp; Confidence plans
                </p>
            </div>
        </section>
    );
}

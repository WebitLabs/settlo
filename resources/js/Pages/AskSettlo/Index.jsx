import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AccountantPanel from './AccountantPanel';
import ChatPanel from './ChatPanel';
import ConversationList from './ConversationList';
import { api, stream } from './api';

function sample(pool, count) {
    return [...pool].sort(() => Math.random() - 0.5).slice(0, count);
}

let tempCounter = 0;
function nextTempId() {
    tempCounter += 1;
    return `temp-${tempCounter}`;
}

export default function Index({ businessEntityId, conversations: initialConversations, activeConversation, context, quota: initialQuota, accountant, suggestedQuestions }) {
    const base = `/ask-settlo/${businessEntityId}`;

    const [conversations, setConversations] = useState(initialConversations);
    const [activeId, setActiveId] = useState(activeConversation?.id ?? null);
    const [conversationTitle, setConversationTitle] = useState(activeConversation?.title ?? 'New conversation');
    const [messages, setMessages] = useState(activeConversation?.messages ?? []);
    const [quota, setQuota] = useState(initialQuota);
    const [streaming, setStreaming] = useState(false);
    const [escalatingId, setEscalatingId] = useState(null);
    const [resolvingId, setResolvingId] = useState(null);
    const [toast, setToast] = useState(null);
    const [chips, setChips] = useState(() => sample(suggestedQuestions, 4));

    const messageRefs = useRef({});

    useEffect(() => {
        if (!toast) {
            return undefined;
        }
        const timer = setTimeout(() => setToast(null), 4000);
        return () => clearTimeout(timer);
    }, [toast]);

    const escalations = useMemo(
        () => messages.filter((m) => m.escalation).map((m) => m.escalation),
        [messages],
    );

    const hasPending = useMemo(
        () => escalations.some((e) => e.status === 'pending' || e.status === 'in_progress'),
        [escalations],
    );

    const refreshActive = useCallback(async () => {
        if (!activeId) {
            return;
        }
        try {
            const data = await api(`${base}/conversations/${activeId}`);
            setMessages(data.messages);
        } catch {
            // transient — the next tick retries
        }
    }, [activeId, base]);

    // Live escalation updates: use Echo when configured, otherwise poll pending
    // escalations every 10s. No new dependency is added for the fallback.
    useEffect(() => {
        if (!hasPending || streaming) {
            return undefined;
        }

        if (window.Echo) {
            const channel = window.Echo.private(`business.${businessEntityId}`);
            channel.listen('.escalation.updated', () => refreshActive());
            return () => {
                channel.stopListening('.escalation.updated');
                window.Echo.leave(`business.${businessEntityId}`);
            };
        }

        const interval = setInterval(refreshActive, 10000);
        return () => clearInterval(interval);
    }, [hasPending, streaming, businessEntityId, refreshActive]);

    const updateSummary = useCallback((id, patch) => {
        setConversations((prev) => prev.map((c) => (c.id === id ? { ...c, ...patch } : c)));
    }, []);

    const selectConversation = useCallback(
        async (id) => {
            if (id === activeId) {
                return;
            }
            try {
                const data = await api(`${base}/conversations/${id}`);
                setActiveId(data.id);
                setConversationTitle(data.title);
                setMessages(data.messages);
                setChips(sample(suggestedQuestions, 4));
            } catch {
                setToast('Could not open that conversation.');
            }
        },
        [activeId, base, suggestedQuestions],
    );

    const newConversation = useCallback(() => {
        setActiveId(null);
        setConversationTitle('New conversation');
        setMessages([]);
        setChips(sample(suggestedQuestions, 4));
    }, [suggestedQuestions]);

    const sendMessage = useCallback(
        async (content) => {
            let conversationId = activeId;
            let isNew = false;

            if (!conversationId) {
                try {
                    const created = await api(`${base}/conversations`, { method: 'POST' });
                    conversationId = created.id;
                    isNew = true;
                    setActiveId(created.id);
                    setConversations((prev) => [
                        { id: created.id, title: content.slice(0, 50), preview: content, group: 'today', badge: null },
                        ...prev,
                    ]);
                } catch {
                    setToast('Could not start a conversation.');
                    return;
                }
            }

            const userTempId = nextTempId();
            const assistantTempId = nextTempId();

            setMessages((prev) => [
                ...prev,
                { tempId: userTempId, role: 'user', content },
                { tempId: assistantTempId, role: 'assistant', content: '', streaming: true },
            ]);
            setStreaming(true);

            if (isNew) {
                setConversationTitle(content.slice(0, 50));
            }
            updateSummary(conversationId, { preview: content });

            const applyDelta = (text) =>
                setMessages((prev) =>
                    prev.map((m) => (m.tempId === assistantTempId ? { ...m, content: m.content + text } : m)),
                );

            const applyDone = (payload) =>
                setMessages((prev) =>
                    prev.map((m) =>
                        m.tempId === assistantTempId
                            ? { ...m, id: payload.id, confidence: payload.confidence, streaming: false }
                            : m,
                    ),
                );

            try {
                await stream(`${base}/conversations/${conversationId}/stream`, content, {
                    onDelta: applyDelta,
                    onDone: applyDone,
                });
            } catch {
                try {
                    const assistant = await api(`${base}/conversations/${conversationId}/messages`, {
                        method: 'POST',
                        body: { content },
                    });
                    setMessages((prev) =>
                        prev.map((m) => (m.tempId === assistantTempId ? { ...assistant, streaming: false } : m)),
                    );
                } catch {
                    setMessages((prev) => prev.filter((m) => m.tempId !== assistantTempId));
                    setToast('Settlo AI is unavailable right now. Please try again.');
                }
            } finally {
                setStreaming(false);
            }
        },
        [activeId, base, updateSummary],
    );

    const escalate = useCallback(
        async (message) => {
            if (!message.id) {
                return;
            }
            setEscalatingId(message.id);
            try {
                const data = await api(`${base}/messages/${message.id}/escalate`, { method: 'POST' });
                setMessages((prev) =>
                    prev.map((m) => (m.id === message.id ? { ...m, escalation: data.escalation } : m)),
                );
                setQuota(data.quota);
                if (activeId) {
                    updateSummary(activeId, { badge: 'pending' });
                }
            } catch (error) {
                setToast(error.message || 'Could not send to accountant.');
            } finally {
                setEscalatingId(null);
            }
        },
        [base, activeId, updateSummary],
    );

    const resolve = useCallback(
        async (escalation) => {
            setResolvingId(escalation.id);
            try {
                const updated = await api(`${base}/escalations/${escalation.id}/resolve`, { method: 'POST' });
                setMessages((prev) =>
                    prev.map((m) => (m.escalation?.id === escalation.id ? { ...m, escalation: updated } : m)),
                );
                if (activeId) {
                    updateSummary(activeId, { badge: 'answered' });
                }
            } catch (error) {
                setToast(error.message || 'Could not resolve.');
            } finally {
                setResolvingId(null);
            }
        },
        [base, activeId, updateSummary],
    );

    const scrollToEscalation = useCallback((escalation) => {
        const node = messageRefs.current[escalation.messageId];
        if (node) {
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    return (
        <>
            <Head title="Ask Settlo" />
            <div className="flex h-screen w-full overflow-hidden bg-[#F0F2F5] font-sans text-[#0D1F2D]">
                <ConversationList
                    conversations={conversations}
                    activeId={activeId}
                    onSelect={selectConversation}
                    onNew={newConversation}
                />

                <ChatPanel
                    context={context}
                    conversationTitle={conversationTitle}
                    messages={messages}
                    streaming={streaming}
                    quota={quota}
                    suggestedChips={chips}
                    onSend={sendMessage}
                    onEscalate={escalate}
                    onResolve={resolve}
                    escalatingId={escalatingId}
                    resolvingId={resolvingId}
                    messageRefs={messageRefs}
                />

                <AccountantPanel
                    accountant={accountant}
                    quota={quota}
                    escalations={escalations}
                    onSelectEscalation={scrollToEscalation}
                />

                {toast && (
                    <div className="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg bg-[#0D1F2D] px-4 py-2.5 text-[13px] text-white shadow-lg">
                        {toast}
                    </div>
                )}
            </div>
        </>
    );
}

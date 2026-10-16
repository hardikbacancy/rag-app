import './bootstrap';
import React, { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { api, errorMessage } from './api';
import UploadForm from './components/UploadForm';
import DocumentList from './components/DocumentList';
import Answer from './components/Answer';
import SourceCard from './components/SourceCard';
import PageModal from './components/PageModal';

function App() {
    const [documents, setDocuments] = useState([]);
    const [selected, setSelected] = useState([]);
    const [question, setQuestion] = useState('');
    const [result, setResult] = useState(null);
    const [asking, setAsking] = useState(false);
    const [error, setError] = useState('');
    const [highlight, setHighlight] = useState(null);
    const [pageSource, setPageSource] = useState(null);
    const [showAllSources, setShowAllSources] = useState(false);

    const loadDocuments = useCallback(async () => {
        try {
            setDocuments(await api.listDocuments());
        } catch (err) {
            setError(errorMessage(err));
        }
    }, []);

    useEffect(() => { loadDocuments(); }, [loadDocuments]);

    // Poll while anything is still being ingested.
    const busy = documents.some((d) => ['pending', 'processing'].includes(d.status));
    useEffect(() => {
        if (!busy) return;
        const timer = setInterval(loadDocuments, 3000);
        return () => clearInterval(timer);
    }, [busy, loadDocuments]);

    // Drop selections for documents that are gone or no longer ready.
    useEffect(() => {
        const ready = new Set(documents.filter((d) => d.status === 'ready').map((d) => d.id));
        setSelected((s) => s.filter((id) => ready.has(id)));
    }, [documents]);

    const readyCount = documents.filter((d) => d.status === 'ready').length;
    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    async function ask(e) {
        e.preventDefault();
        if (question.trim().length < 3) return;
        setAsking(true);
        setError('');
        setResult(null);
        setShowAllSources(false);
        try {
            setResult(await api.ask(question.trim(), selected.length ? selected : null));
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setAsking(false);
        }
    }

    function cite(label) {
        setShowAllSources(true);
        setHighlight(label);
        setTimeout(() => document.getElementById(`source-${label}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50);
    }

    const sources = result ? (showAllSources ? result.sources : result.citations) : [];

    return (
        <div className="min-h-screen bg-slate-50">
            <header className="border-b border-slate-200 bg-white px-6 py-3">
                <h1 className="text-lg font-semibold text-slate-800">Document Intelligence RAG</h1>
                <p className="text-xs text-slate-500">Upload PDFs, ask questions, get answers with page-level citations.</p>
            </header>

            <div className="mx-auto grid max-w-7xl gap-6 p-6 lg:grid-cols-[320px_1fr]">
                <aside className="space-y-4">
                    <UploadForm onUploaded={loadDocuments} />
                    <div>
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Documents</h2>
                        <DocumentList documents={documents} selected={selected} onToggle={toggle} onChanged={loadDocuments} />
                    </div>
                </aside>

                <main className="space-y-4">
                    <form onSubmit={ask} className="rounded-lg border border-slate-200 bg-white p-4">
                        <textarea value={question} onChange={(e) => setQuestion(e.target.value)} rows={3} maxLength={1000}
                            placeholder="Ask a question about your documents…"
                            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(e); } }}
                            className="w-full resize-y rounded border border-slate-300 p-2 text-sm" />
                        <div className="mt-2 flex items-center justify-between gap-2">
                            <span className="text-xs text-slate-500">
                                Searching: {selected.length ? `${selected.length} selected document(s)` : `all ${readyCount} ready document(s)`}
                            </span>
                            <button type="submit" disabled={asking || readyCount === 0 || question.trim().length < 3}
                                className="rounded bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                                {asking ? 'Thinking…' : 'Ask'}
                            </button>
                        </div>
                    </form>

                    {error && <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}

                    {result && (
                        <>
                            <section className="rounded-lg border border-slate-200 bg-white p-4">
                                <h2 className="mb-2 text-sm font-semibold text-slate-700">Answer</h2>
                                <Answer text={result.answer} onCite={cite} />
                            </section>

                            {result.sources.length > 0 && (
                                <section>
                                    <div className="mb-2 flex items-center justify-between">
                                        <h2 className="text-sm font-semibold text-slate-700">
                                            {showAllSources ? `All retrieved sources (${result.sources.length})` : `Cited sources (${result.citations.length})`}
                                        </h2>
                                        <button onClick={() => setShowAllSources(!showAllSources)} className="text-xs text-indigo-600 hover:underline">
                                            {showAllSources ? 'Show cited only' : 'Show all retrieved'}
                                        </button>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {sources.map((s) => (
                                            <SourceCard key={s.label} source={s} highlighted={highlight === s.label} onViewPage={setPageSource} />
                                        ))}
                                    </div>
                                </section>
                            )}
                        </>
                    )}
                </main>
            </div>

            <PageModal source={pageSource} onClose={() => setPageSource(null)} />
        </div>
    );
}

createRoot(document.getElementById('app')).render(<App />);

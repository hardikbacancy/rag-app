import React from 'react';
import { api, errorMessage } from '../api';

const STATUS_STYLES = {
    pending: 'bg-slate-100 text-slate-700',
    processing: 'bg-amber-100 text-amber-800',
    ready: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
};

export default function DocumentList({ documents, selected, onToggle, onChanged }) {
    async function reingest(doc) {
        try {
            await api.reingest(doc.id, doc.strategy);
            onChanged();
        } catch (err) {
            alert(errorMessage(err));
        }
    }

    async function remove(doc) {
        if (!confirm(`Delete "${doc.title}" and all its chunks?`)) return;
        await api.deleteDocument(doc.id);
        onChanged();
    }

    if (documents.length === 0) {
        return <p className="text-sm text-slate-500">No documents yet. Upload a PDF to get started.</p>;
    }

    return (
        <ul className="space-y-2">
            {documents.map((doc) => (
                <li key={doc.id} className="rounded-lg border border-slate-200 bg-white p-3">
                    <label className="flex cursor-pointer items-start gap-2">
                        <input type="checkbox" className="mt-1" disabled={doc.status !== 'ready'}
                            checked={selected.includes(doc.id)} onChange={() => onToggle(doc.id)} />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-800" title={doc.title}>{doc.title}</span>
                            <span className="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                <span className={`rounded px-1.5 py-0.5 ${STATUS_STYLES[doc.status]}`}>{doc.status}</span>
                                <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-indigo-700">{doc.strategy}</span>
                                {doc.page_count && <span className="text-slate-500">{doc.page_count} pages · {doc.chunk_count} chunks</span>}
                            </span>
                        </span>
                    </label>

                    {['pending', 'processing'].includes(doc.status) && (
                        <div className="mt-2">
                            <div className="h-1.5 overflow-hidden rounded bg-slate-100">
                                <div className="h-full bg-amber-500 transition-all" style={{ width: `${doc.progress}%` }} />
                            </div>
                            <p className="mt-1 text-xs text-slate-500">{doc.status_message}</p>
                        </div>
                    )}
                    {doc.status === 'failed' && <p className="mt-2 break-words text-xs text-red-600">{doc.error}</p>}

                    <div className="mt-2 flex gap-3 text-xs">
                        {doc.status !== 'processing' && <button onClick={() => reingest(doc)} className="text-indigo-600 hover:underline">Re-ingest</button>}
                        <button onClick={() => remove(doc)} className="text-red-600 hover:underline">Delete</button>
                    </div>
                </li>
            ))}
        </ul>
    );
}

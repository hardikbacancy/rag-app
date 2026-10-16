import React from 'react';
import { api } from '../api';

export default function PageModal({ source, onClose }) {
    if (!source) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
            <div className="max-h-full max-w-3xl overflow-auto rounded-lg bg-white p-3" onClick={(e) => e.stopPropagation()}>
                <div className="mb-2 flex items-center justify-between gap-4 text-sm">
                    <span className="font-medium">{source.document_title} — page {source.page}</span>
                    <button onClick={onClose} className="rounded px-2 py-1 text-slate-500 hover:bg-slate-100">Close</button>
                </div>
                <img src={api.pageImageUrl(source.document_id, source.page)} alt={`Page ${source.page}`} className="w-full border" />
            </div>
        </div>
    );
}

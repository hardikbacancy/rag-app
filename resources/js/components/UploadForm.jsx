import React, { useState } from 'react';
import { api, errorMessage, STRATEGIES } from '../api';

export default function UploadForm({ onUploaded }) {
    const [file, setFile] = useState(null);
    const [title, setTitle] = useState('');
    const [strategy, setStrategy] = useState('baseline');
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState('');

    async function submit(e) {
        e.preventDefault();
        if (!file) return;
        setError('');
        const form = new FormData();
        form.append('file', file);
        form.append('title', title);
        form.append('strategy', strategy);
        try {
            setProgress(0);
            await api.uploadDocument(form, setProgress);
            setFile(null);
            setTitle('');
            e.target.reset();
            onUploaded();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setProgress(null);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-2 rounded-lg border border-slate-200 bg-white p-3">
            <h2 className="text-sm font-semibold text-slate-700">Upload PDF</h2>
            <input type="file" accept="application/pdf" onChange={(e) => setFile(e.target.files[0] || null)}
                className="block w-full text-sm file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1" />
            <input type="text" placeholder="Title (optional)" value={title} maxLength={200}
                onChange={(e) => setTitle(e.target.value)} className="w-full rounded border border-slate-300 px-2 py-1 text-sm" />
            <select value={strategy} onChange={(e) => setStrategy(e.target.value)} className="w-full rounded border border-slate-300 px-2 py-1 text-sm">
                {Object.entries(STRATEGIES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
            <button type="submit" disabled={!file || progress !== null}
                className="w-full rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                {progress !== null ? `Uploading ${progress}%` : 'Upload & ingest'}
            </button>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </form>
    );
}

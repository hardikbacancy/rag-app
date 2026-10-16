import React, { useState } from 'react';
import { api } from '../api';

const TYPE_STYLES = {
    text: 'bg-slate-100 text-slate-700',
    table: 'bg-sky-100 text-sky-800',
    figure: 'bg-violet-100 text-violet-800',
    footnote: 'bg-orange-100 text-orange-800',
};

export default function SourceCard({ source, highlighted, onViewPage }) {
    const [expanded, setExpanded] = useState(false);
    const text = expanded || source.content.length <= 320 ? source.content : source.content.slice(0, 320) + '…';

    return (
        <div id={`source-${source.label}`}
            className={`rounded-lg border bg-white p-3 text-sm transition ${highlighted ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-slate-200'} ${source.cited ? '' : 'opacity-70'}`}>
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="rounded bg-indigo-600 px-1.5 py-0.5 font-semibold text-white">{source.label}</span>
                <span className="font-medium text-slate-800">Page {source.page}</span>
                <span className={`rounded px-1.5 py-0.5 ${TYPE_STYLES[source.type] || TYPE_STYLES.text}`}>{source.type}</span>
                {source.cited && <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800">cited</span>}
                <span className="text-slate-400">score {source.score}</span>
            </div>
            <p className="mt-1 truncate text-xs text-slate-500" title={source.document_title}>{source.document_title}</p>
            {source.section && <p className="mt-0.5 text-xs text-slate-500">§ {source.section}</p>}
            <p className="mt-2 whitespace-pre-wrap text-slate-700">{text}</p>
            <div className="mt-2 flex gap-3 text-xs">
                {source.content.length > 320 && (
                    <button onClick={() => setExpanded(!expanded)} className="text-indigo-600 hover:underline">{expanded ? 'Show less' : 'Show more'}</button>
                )}
                <button onClick={() => onViewPage(source)} className="text-indigo-600 hover:underline">View page</button>
                <a href={api.pdfUrl(source.document_id, source.page)} target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">Open PDF at page</a>
            </div>
        </div>
    );
}

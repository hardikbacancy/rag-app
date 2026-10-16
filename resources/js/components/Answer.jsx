import React from 'react';

// Renders the answer text, turning [S1] markers into clickable chips and **bold** into <strong>.
export default function Answer({ text, onCite }) {
    return (
        <div className="space-y-2 leading-relaxed text-slate-800">
            {text.split(/\n{2,}/).map((paragraph, i) => (
                <p key={i} className="whitespace-pre-wrap">
                    {paragraph.split(/(\[S\d+\]|\*\*[^*]+\*\*)/g).map((part, j) => {
                        const cite = part.match(/^\[(S\d+)\]$/);
                        if (cite) {
                            return (
                                <button key={j} onClick={() => onCite(cite[1])}
                                    className="mx-0.5 rounded bg-indigo-100 px-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-200">
                                    {cite[1]}
                                </button>
                            );
                        }
                        if (part.startsWith('**') && part.endsWith('**')) return <strong key={j}>{part.slice(2, -2)}</strong>;
                        return <React.Fragment key={j}>{part}</React.Fragment>;
                    })}
                </p>
            ))}
        </div>
    );
}

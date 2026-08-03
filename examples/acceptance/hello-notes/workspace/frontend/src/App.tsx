import { useMemo, useState } from 'react';

type Note = { id: number; title: string; body: string };

export default function App() {
  const [notes, setNotes] = useState<Note[]>([]);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [query, setQuery] = useState('');

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return notes;
    return notes.filter(
      (n) => n.title.toLowerCase().includes(q) || n.body.toLowerCase().includes(q),
    );
  }, [notes, query]);

  return (
    <main>
      <h1>Hello Notes</h1>
      <input placeholder="Search" value={query} onChange={(e) => setQuery(e.target.value)} />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setNotes((prev) => [...prev, { id: Date.now(), title, body }]);
          setTitle('');
          setBody('');
        }}
      >
        <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title" />
        <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Body" />
        <button type="submit">Add note</button>
      </form>
      <ul>
        {visible.map((n) => (
          <li key={n.id}>
            <strong>{n.title}</strong>
            <p>{n.body}</p>
          </li>
        ))}
      </ul>
    </main>
  );
}

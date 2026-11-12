import { BrowserRouter, Route, Routes } from 'react-router'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={
          <main className="min-h-screen bg-slate-50 p-8 text-slate-900">
            <h1 className="text-3xl font-semibold">JobDating</h1>
            <p className="mt-4">Socle technique initialisé.</p>
          </main>
        } />
      </Routes>
    </BrowserRouter>
  )
}

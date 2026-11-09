import { Link, Route, Routes } from 'react-router-dom';
import { Layout } from './components/Layout';
import { ProtectedRoute } from './components/ProtectedRoute';
import AnonymousSign from './pages/AnonymousSign';
import Dashboard from './pages/Dashboard';
import DocumentDetail from './pages/DocumentDetail';
import Login from './pages/Login';
import Register from './pages/Register';

function NotFound() {
  return (
    <section className="empty-state">
      <span aria-hidden="true">🧭</span>
      <h1>Página no encontrada</h1>
      <p>La dirección que abriste no existe.</p>
      <Link to="/" className="btn btn--primary">
        Volver al inicio
      </Link>
    </section>
  );
}

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<AnonymousSign />} />
        <Route path="login" element={<Login />} />
        <Route path="register" element={<Register />} />
        <Route
          path="app"
          element={
            <ProtectedRoute>
              <Dashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="app/documents/:uuid"
          element={
            <ProtectedRoute>
              <DocumentDetail />
            </ProtectedRoute>
          }
        />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  );
}

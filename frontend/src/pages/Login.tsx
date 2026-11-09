import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const from = (location.state as { from?: string } | null)?.from ?? '/app';

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await login(email, password);
      navigate(from, { replace: true });
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.fieldError('email') ??
              requestError.errors
                .map((item) => item.detail)
                .join(' ')
          : 'No se pudo iniciar sesión. Intenta nuevamente.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-page">
      <section className="card auth-card">
        <h1>Ingresar</h1>
        <p className="muted">
          Accede para crear documentos, compartirlos con firmantes y gestionar el cierre y la eliminación.
        </p>

        <form className="stack" onSubmit={submit}>
          <div className="field">
            <label className="field__label" htmlFor="email">
              Correo electrónico
            </label>
            <input
              id="email"
              className="input"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </div>

          <div className="field">
            <label className="field__label" htmlFor="password">
              Contraseña
            </label>
            <input
              id="password"
              className="input"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
          </div>

          {error && <div className="alert alert--danger">{error}</div>}

          <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
            {submitting ? 'Ingresando…' : 'Ingresar'}
          </button>
        </form>

        <p className="auth-card__footer">
          ¿No tienes cuenta? <Link to="/register">Crea una gratis</Link>
        </p>
      </section>
    </div>
  );
}

import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';

export default function Register() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await register({
        name,
        email,
        password,
        password_confirmation: confirmation,
      });
      navigate('/app', { replace: true });
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.errors.map((item) => item.detail).join(' ')
          : 'No se pudo crear la cuenta. Intenta nuevamente.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-page">
      <section className="card auth-card">
        <h1>Crear cuenta</h1>
        <p className="muted">
          Con una cuenta puedes invitar firmantes, requerir varias firmas por documento y cerrar el
          documento cuando firma la última persona.
        </p>

        <form className="stack" onSubmit={submit}>
          <div className="field">
            <label className="field__label" htmlFor="name">
              Nombre y apellido
            </label>
            <input
              id="name"
              className="input"
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={120}
              required
            />
          </div>

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

          <div className="two-columns">
            <div className="field">
              <label className="field__label" htmlFor="password">
                Contraseña
              </label>
              <input
                id="password"
                className="input"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                minLength={8}
                required
              />
              <span className="hint">Al menos 8 caracteres.</span>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="confirmation">
                Repetir contraseña
              </label>
              <input
                id="confirmation"
                className="input"
                type="password"
                autoComplete="new-password"
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                minLength={8}
                required
              />
            </div>
          </div>

          {error && <div className="alert alert--danger">{error}</div>}

          <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
            {submitting ? 'Creando cuenta…' : 'Crear cuenta'}
          </button>
        </form>

        <p className="auth-card__footer">
          ¿Ya tienes cuenta? <Link to="/login">Ingresa</Link>
        </p>
      </section>
    </div>
  );
}

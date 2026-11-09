import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';

export function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    navigate('/');
  };

  return (
    <div className="app-shell">
      <header className="site-header">
        <div className="site-header__inner">
          <Link to="/" className="brand">
            <span className="brand__mark" aria-hidden="true">
              ✍
            </span>
            <span>
              <strong>Firma Digital</strong>
              <small>Documentos PDF firmados con certificado .p12</small>
            </span>
          </Link>

          <nav className="site-nav">
            <NavLink to="/" end>
              Firmar sin cuenta
            </NavLink>
            <NavLink to="/app">Mis documentos</NavLink>
            <a href="/api/v1/docs" target="_blank" rel="noreferrer">
              API
            </a>
          </nav>

          <div className="site-session">
            {user ? (
              <>
                <span className="user-chip" title={user.attributes.email}>
                  {user.attributes.name}
                </span>
                <button type="button" className="btn btn--ghost btn--sm" onClick={handleLogout}>
                  Salir
                </button>
              </>
            ) : (
              <>
                <Link to="/login" className="btn btn--ghost btn--sm">
                  Ingresar
                </Link>
                <Link to="/register" className="btn btn--primary btn--sm">
                  Crear cuenta
                </Link>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="site-main">
        <Outlet />
      </main>

      <footer className="site-footer">
        <div>
          <strong>Firma Digital</strong> · Laravel 12 · PostgreSQL 14 · React
        </div>
        <div>
          <a href="/api/v1/docs" target="_blank" rel="noreferrer">
            Documentación OpenAPI
          </a>
        </div>
      </footer>
    </div>
  );
}

import { Link, NavLink, Outlet } from 'react-router'
import Container from './Container.jsx'
import { useAuth } from '../context/AuthContext.jsx'

import styles from './AppLayout.module.scss'

export default function AppLayout() {
  const { user } = useAuth()

  return (
    <>
      <a className={styles.skipLink} href="#main-content">
        Saltar al contenido
      </a>
      <header className={styles.header}>
        <Container className={styles.headerInner}>
          <Link className={styles.brand} to="/">
            <span aria-hidden="true" className={styles.monogram}>
              FC
            </span>
            <span>Formación y coaching</span>
          </Link>
          <nav className={styles.navigation} aria-label="Navegación principal">
            <NavLink to="/" end>
              Inicio
            </NavLink>
            <NavLink to="/courses">Cursos</NavLink>
            <NavLink to="/account">Mi cuenta</NavLink>

            {user && (
              <>
                <Link
                  to={`/profiles/${user.id}`}
                  aria-label="Ver mi perfil"
                  className={styles.avatarLink}
                >
                  {user.avatar_url ? (
                    <img
                      className={styles.headerAvatar}
                      src={user.avatar_url}
                      alt=""
                      width={32}
                      height={32}
                      decoding="async"
                    />
                  ) : (
                    <span
                      className={styles.headerAvatarPlaceholder}
                      aria-hidden="true"
                    >
                      {[...user.name][0]?.toUpperCase() ?? '?'}
                    </span>
                  )}
                </Link>
              </>
            )}
          </nav>
        </Container>
      </header>

      <Container
        as="main"
        id="main-content"
        tabIndex={-1}
        className={styles.main}
      >
        <Outlet key={user?.id ?? 'guest'} />
      </Container>
      <footer className={styles.footer}>
        <Container>Formación online, a tu ritmo.</Container>
      </footer>
    </>
  )
}

import { Link, NavLink, Outlet } from 'react-router'
import Container from './Container.jsx'
import styles from './AppLayout.module.scss'

export default function AppLayout() {
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
            <NavLink to="/account">Mi cuenta</NavLink>
          </nav>
        </Container>
      </header>
      <Container
        as="main"
        id="main-content"
        tabIndex={-1}
        className={styles.main}
      >
        <Outlet />
      </Container>
      <footer className={styles.footer}>
        <Container>Formación online, a tu ritmo.</Container>
      </footer>
    </>
  )
}

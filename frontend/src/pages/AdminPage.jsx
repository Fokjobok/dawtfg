import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { getSession } from '../services/auth.js'
import { getUsers } from '../services/users.js'
import FormField from '../components/FormField.jsx'

import Button from '../components/Button.jsx'
import EditUserForm from '../components/EditUserForm.jsx'

import { ROLE_LABELS } from '../roles.js'

export default function AdminPage() {
  const [user, setUser] = useState(null)
  const [sessionStatus, setSessionStatus] = useState('loading')

  const [selectedRole, setSelectedRole] = useState('')

  //const [status, setStatus] = useState('loading')
  const [users, setUsers] = useState([])
  const [usersStatus, setUsersStatus] = useState('idle')

  const [editingUser, setEditingUser] = useState(null)

  function handleUserSaved(updated) {
    setUsers((current) =>
      current
        .map((account) => (account.id === updated.id ? updated : account))
        .filter((account) => account.role === selectedRole),
    )

    setEditingUser(null)
  }

  useEffect(() => {
    let active = true

    getSession()
      .then((session) => {
        if (!active) return

        setUser(session)
        setSessionStatus('ready')
      })
      .catch(() => {
        if (active) {
          setSessionStatus('error')
        }
      })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (user?.role !== 'Administrator' || !selectedRole) {
      return
    }

    let active = true

    async function loadUsers() {
      try {
        const response = await getUsers(1, selectedRole)

        if (!active) return

        setUsers(response.data)
        setUsersStatus('ready')
      } catch (error) {
        if (!active) return

        if (error.status === 401) {
          setUser(null)
        }

        setUsersStatus('error')
      }
    }

    loadUsers()

    return () => {
      active = false
    }
  }, [selectedRole, user])

  function handleRoleChange(event) {
    const role = event.target.value

    setSelectedRole(role)
    setUsers([])
    setUsersStatus(role ? 'loading' : 'idle')
    setEditingUser(null)
  }

  if (sessionStatus === 'loading') {
    return <p role="status">Comprobando la sesión…</p>
  }

  if (sessionStatus === 'error') {
    return <p role="alert">No se pudo comprobar la sesión.</p>
  }

  if (!user) {
    return <Link to="/account">Inicia sesión para continuar</Link>
  }

  if (user.role !== 'Administrator') {
    return <p>No tienes permisos para acceder a administración.</p>
  }

  return (
    <section aria-labelledby="admin-title">
      <h1 id="admin-title">Administración</h1>
      <p>Gestiona los usuarios y las especialidades de la plataforma.</p>

      <h2>Usuarios</h2>

      <FormField
        as="select"
        id="role-filter"
        label="Tipo de usuario"
        value={selectedRole}
        onChange={handleRoleChange}
      >
        <option value="">Selecciona un tipo de usuario</option>
        <option value="Coach">Coaches</option>
        <option value="Student">Estudiantes</option>
      </FormField>

      {!selectedRole && (
        <p>Selecciona un tipo de usuario para consultar el listado.</p>
      )}

      {usersStatus === 'loading' && <p role="status">Cargando usuarios…</p>}

      {usersStatus === 'error' && (
        <p role="alert">No se pudo cargar el listado de usuarios.</p>
      )}

      {usersStatus === 'ready' &&
        (users.length === 0 ? (
          <p>No hay usuarios de este tipo para mostrar.</p>
        ) : (
          <ul>
            {users.map((account) => (
              <li key={account.id}>
                <strong>{account.name}</strong>
                {' — '}
                {account.email}
                {' — '}
                {ROLE_LABELS[account.role] ?? account.role}

                <Button onClick={() => setEditingUser(account)}>Editar</Button>
              </li>
            ))}
          </ul>
        ))}

      {editingUser && (
        <EditUserForm
          key={editingUser.id}
          account={editingUser}
          onSaved={handleUserSaved}
          onCancel={() => setEditingUser(null)}
        />
      )}
    </section>
  )
}
